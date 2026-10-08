<?php
/**
 * AclCest
 *
 * Project isolation on routes that used to miss an ACL regex (the package ACL
 * middleware let them through), on the target project of a cross-project copy,
 * and on a changelog rollback after a move. Run only against a disposable HTTP
 * and MongoDB environment (the suite drops its database).
 */

class AclCest
{
    /**
     * A flow of the current project, on a table created for it.
     */
    private function createFlow(ApiTester $I)
    {
        $table = $I->createTable();
        $inputs = array_map(function ($field) {
            return ['key' => $field->key, 'type' => $field->type];
        }, $table->fields);
        $I->sendPOST('api/v1/admin/flows', [
            'title' => 'ACL flow',
            'inputs' => $inputs,
            'nodes' => [['node_id' => 'n1', 'table_id' => $table->_id]],
            'edges' => [],
            'outputs' => [['name' => 'decision', 'from_node' => 'n1', 'from_output' => 'final_decision']],
        ]);
        $I->seeResponseCodeIs(201);

        return $I->getResponseFields()->data;
    }

    /**
     * Add $user to the current project (X-Application) as a manager with $scope.
     */
    private function addMember(ApiTester $I, $user, array $scope)
    {
        $I->sendPOST('api/v1/projects/users', ['user_id' => $user->_id, 'role' => 'manager', 'scope' => $scope]);
        $I->seeResponseCodeIs(201);
    }

    public function nonMemberCannotCopyAFlow(ApiTester $I)
    {
        $owner = $I->createUser(true);
        $outsider = $I->createUser(true);

        $I->loginUser($owner);
        $I->createProjectAndSetHeader();
        $flow = $this->createFlow($I);

        $I->loginUser($outsider);
        $I->sendPOST('api/v1/admin/flows/' . $flow->_id . '/copy');
        $I->seeResponseCodeIs(403);

        $I->loginUser($owner);
        $I->sendGET('api/v1/admin/flows');
        $I->seeResponseCodeIs(200);
        $I->assertCount(1, $I->getResponseFields()->data);
    }

    public function onlyProjectUpdateCanEditTheProject(ApiTester $I)
    {
        $owner = $I->createUser(true);
        $viewer = $I->createUser(true);
        $outsider = $I->createUser(true);

        $I->loginUser($owner);
        $I->createProjectAndSetHeader();
        $this->addMember($I, $viewer, ['tables_view']);

        $I->loginUser($outsider);
        $I->sendPUT('api/v1/projects', ['description' => 'Edited by an outsider']);
        $I->seeResponseCodeIs(403);

        $I->loginUser($viewer);
        $I->sendPUT('api/v1/projects', ['description' => 'Edited by a viewer']);
        $I->seeResponseCodeIs(403);
        $I->seeResponseContains('project_update');

        $I->loginUser($owner);
        $I->sendPUT('api/v1/projects', ['description' => 'Edited by the admin']);
        $I->seeResponseCodeIs(200);
    }

    public function copyNeedsTablesCreateInTheTarget(ApiTester $I)
    {
        $admin = $I->createUser(true);
        $other = $I->createUser(true);

        // $other owns project B and lets $admin only view it.
        $I->loginUser($other);
        $target = $I->createProject(true);
        $I->setHeader('X-Application', $target->_id);
        $this->addMember($I, $admin, ['tables_view']);

        // $admin owns project A.
        $I->loginUser($admin);
        $source = $I->createProject(true);
        $I->setHeader('X-Application', $source->_id);
        $table = $I->createTable();

        $I->sendPOST('api/v1/admin/tables/' . $table->_id . '/copyto/' . $target->_id);
        $I->seeResponseCodeIs(403);
        $I->seeResponseContains('tables_create');

        $I->loginUser($other);
        $I->setHeader('X-Application', $target->_id);
        $I->sendPUT('api/v1/projects/users', [
            'user_id' => $admin->_id,
            'role' => 'manager',
            'scope' => ['tables_view', 'tables_create'],
        ]);
        $I->seeResponseCodeIs(200);

        $I->loginUser($admin);
        $I->setHeader('X-Application', $source->_id);
        $I->sendPOST('api/v1/admin/tables/' . $table->_id . '/copyto/' . $target->_id);
        $I->seeResponseCodeIs(200);
    }

    public function rollbackDoesNotPullBackAMovedTable(ApiTester $I)
    {
        $I->createAndLoginUser(true);
        $source = $I->createProject(true);
        $target = $I->createProject(true);

        $I->setHeader('X-Application', $source->_id);
        $table = $I->createTable();
        $I->sendGET('api/v1/admin/changelog/tables/' . $table->_id);
        $I->seeResponseCodeIs(200);
        $snapshot = $I->getResponseFields()->data[0];

        $I->sendPOST('api/v1/admin/tables/' . $table->_id . '/moveto/' . $target->_id);
        $I->seeResponseCodeIs(200);

        $I->setHeader('X-Application', $target->_id);
        $I->sendPUT('api/v1/admin/tables/' . $table->_id, array_merge($I->getTableData(), ['title' => 'Edited in the target']));
        $I->seeResponseCodeIs(200);

        // A snapshot taken before the move still belongs to the source project.
        $I->setHeader('X-Application', $source->_id);
        $I->sendPOST('api/v1/admin/changelog/tables/' . $table->_id . '/rollback/' . $snapshot->_id);
        $I->seeResponseCodeIs(404);

        $I->setHeader('X-Application', $target->_id);
        $I->sendGET('api/v1/admin/tables/' . $table->_id);
        $I->seeResponseCodeIs(200);
        $I->assertEquals('Edited in the target', $I->getResponseFields()->data->title);
    }

    public function inviteNeedsUsersManage(ApiTester $I)
    {
        $owner = $I->createUser(true);
        $viewer = $I->createUser(true);

        $I->loginUser($owner);
        $I->createProjectAndSetHeader();
        $this->addMember($I, $viewer, ['tables_view']);

        $I->loginUser($viewer);
        $I->sendPOST('api/v1/invite', ['email' => 'invitee@example.com', 'role' => 'manager', 'scope' => ['tables_view']]);
        $I->seeResponseCodeIs(403);
        $I->seeResponseContains('users_manage');
    }

    public function onlyAnAdminInvitesAnAdmin(ApiTester $I)
    {
        $owner = $I->createUser(true);
        $manager = $I->createUser(true);

        $I->loginUser($owner);
        $I->createProjectAndSetHeader();
        $this->addMember($I, $manager, ['tables_view', 'users_manage']);

        $I->loginUser($manager);
        $I->sendPOST('api/v1/invite', ['email' => 'invitee@example.com', 'role' => 'admin', 'scope' => ['tables_view']]);
        $I->seeResponseCodeIs(403);
        $I->seeResponseContains('Only a project admin can invite an admin.');
    }
}
