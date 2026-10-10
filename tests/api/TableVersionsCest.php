<?php
/**
 * TableVersionsCest
 *
 * Table versions, batch 2 (VERSIONS.md): drafts, publication, activation,
 * variant split, deletion guards and the tables_publish / tables_activate
 * scopes. Run only against a disposable HTTP and MongoDB environment (the
 * suite drops its database).
 */

class TableVersionsCest
{
    private function versionsUrl($table, $version = null, $action = null)
    {
        return 'api/v1/admin/tables/' . $table->_id . '/versions'
            . ($version ? '/' . $version : '')
            . ($action ? '/' . $action : '');
    }

    private function createDraft(ApiTester $I, $table, array $data = [])
    {
        $I->sendPOST($this->versionsUrl($table), $data);
        $I->seeResponseCodeIs(201);

        return $I->getResponseFields()->data;
    }

    /**
     * A draft with a changed default decision, published.
     */
    private function publishChange(ApiTester $I, $table, $defaultDecision, $from = null)
    {
        $draft = $this->createDraft($I, $table, $from ? ['from_version_id' => $from] : []);
        $content = json_decode(json_encode($draft), true);
        $content['variants'][0]['default_decision'] = $defaultDecision;
        $I->sendPUT($this->versionsUrl($table, $draft->_id), [
            'fields' => $content['fields'],
            'variants' => $content['variants'],
            'variants_probability' => $content['variants_probability'],
        ]);
        $I->seeResponseCodeIs(200);
        $I->sendPOST($this->versionsUrl($table, $draft->_id, 'publish'));
        $I->seeResponseCodeIs(200);

        return $I->getResponseFields();
    }

    private function activate(ApiTester $I, $table, $versionId)
    {
        $I->sendPOST('api/v1/admin/tables/' . $table->_id . '/activate', ['version_id' => $versionId]);
        $I->seeResponseCodeIs(200);

        return $I->getResponseFields();
    }

    public function aDraftRunsOnlyOncePublishedAndActivated(ApiTester $I)
    {
        $I->createAndLoginUser();
        $I->createProjectAndSetHeader();
        $table = $I->createTable();
        $version1 = $table->active_version_id;

        $draft = $this->createDraft($I, $table, ['name' => 'Stricter']);
        $I->assertEquals('draft', $draft->status);
        $I->assertEquals($version1, $draft->origin_version_id);
        $I->assertFalse($draft->is_active);
        $I->assertEquals($table->variants[0]->_id, $draft->variants[0]->_id, 'Variant ids are kept');

        $I->sendPUT($this->versionsUrl($table, $draft->_id), ['name' => 'No content']);
        $I->seeResponseCodeIs(422);

        $published = $this->publishChange($I, $table, 'Changed default');
        $I->assertEquals(2, $published->data->number);
        $I->assertEquals(1, $I->makeDecision($table->_id)->table->version->number, 'Publishing does not activate');

        $I->sendPUT($this->versionsUrl($table, $published->data->_id), ['fields' => [], 'variants' => []]);
        $I->seeResponseCodeIs(409);
        $I->seeResponseContains('version_published');

        $activated = $this->activate($I, $table, $published->data->_id);
        $I->assertEquals($published->data->_id, $activated->data->active_version_id);
        $I->assertEquals('Changed default', $activated->data->variants[0]->default_decision);
        $I->assertEquals(2, $I->makeDecision($table->_id)->table->version->number);

        $I->sendGET($this->versionsUrl($table));
        $I->seeResponseCodeIs(200);
        $I->dontSeeResponseJsonMatchesJsonPath('$.data[*].fields');
    }

    public function aVersionThatSkipsTheActiveOneWarns(ApiTester $I)
    {
        $I->createAndLoginUser();
        $I->createProjectAndSetHeader();
        $table = $I->createTable();
        $version1 = $table->active_version_id;

        $version2 = $this->publishChange($I, $table, 'Version 2');
        $I->assertFalse(isset($version2->meta->warning), 'Branched from the active version');
        $this->activate($I, $table, $version2->data->_id);

        // Branched from version 1, published after version 2 went live.
        $version3 = $this->publishChange($I, $table, 'Version 3', $version1);
        $I->assertEquals('version_diverges', $version3->meta->warning);
        $I->assertEquals(2, $version3->meta->active_version->number);

        // A rollback is a divergence too: version 2's changes are dropped.
        $rollback = $this->activate($I, $table, $version1);
        $I->assertEquals('version_diverges', $rollback->meta->warning);
        $I->assertEquals(1, $I->makeDecision($table->_id)->table->version->number);
    }

    public function theSplitChangesWithoutANewVersion(ApiTester $I)
    {
        $I->createAndLoginUser();
        $I->createProjectAndSetHeader();
        $data = $I->getTableData();
        $data['variants'][1] = ['_id' => $I->getMongoId(), 'title' => 'Second variant', 'default_decision' => 'Decline', 'rules' => []];
        $data['variants_probability'] = 'first';
        $table = $I->createTable($data);
        $first = $table->variants[0]->_id;
        $second = $table->variants[1]->_id;

        $I->sendPUT($this->versionsUrl($table, $table->active_version_id, 'split'), [
            'variants_probability' => 'percent',
            'variants' => [['_id' => $first, 'probability' => 30], ['_id' => $second, 'probability' => 70]],
        ]);
        $I->seeResponseCodeIs(200);

        $I->sendGET('api/v1/admin/tables/' . $table->_id);
        $I->assertEquals('percent', $I->getResponseFields()->data->variants_probability);
        $I->assertEquals($table->active_version_id, $I->getResponseFields()->data->active_version_id);

        $I->sendPUT($this->versionsUrl($table, $table->active_version_id, 'split'), [
            'variants_probability' => 'percent',
            'variants' => [['_id' => $first, 'probability' => 50]],
        ]);
        $I->seeResponseCodeIs(422);

        $I->sendPUT($this->versionsUrl($table, $table->active_version_id, 'split'), [
            'variants_probability' => 'first',
            'variants' => [['_id' => $I->getMongoId(), 'probability' => 0]],
        ]);
        $I->seeResponseCodeIs(422);
        $I->seeResponseContains('variant_not_found');

        $I->sendGET($this->versionsUrl($table));
        $I->assertCount(1, $I->getResponseFields()->data);
    }

    public function theActiveVersionCannotBeDeleted(ApiTester $I)
    {
        $I->createAndLoginUser();
        $I->createProjectAndSetHeader();
        $table = $I->createTable();

        $I->sendDELETE($this->versionsUrl($table, $table->active_version_id));
        $I->seeResponseCodeIs(409);
        $I->seeResponseContains('version_in_use');

        $draft = $this->createDraft($I, $table);
        $I->sendDELETE($this->versionsUrl($table, $draft->_id));
        $I->seeResponseCodeIs(200);
        $I->sendGET($this->versionsUrl($table, $draft->_id));
        $I->seeResponseCodeIs(404);
        $I->seeResponseContains('version_not_found');
    }

    public function publishingAndActivatingNeedTheirScopes(ApiTester $I)
    {
        $owner = $I->createUser(true);
        $editor = $I->createUser(true);

        $I->loginUser($owner);
        $I->createProjectAndSetHeader();
        $table = $I->createTable();
        $published = $this->publishChange($I, $table, 'Published by the owner');
        $I->sendPOST('api/v1/projects/users', [
            'user_id' => $editor->_id,
            'role' => 'manager',
            'scope' => ['tables_view', 'tables_create', 'tables_update', 'decisions_view'],
        ]);
        $I->seeResponseCodeIs(201);

        $I->loginUser($editor);
        $draft = $this->createDraft($I, $table);

        $I->sendPOST($this->versionsUrl($table, $draft->_id, 'publish'));
        $I->seeResponseCodeIs(403);
        $I->seeResponseContains('tables_publish');

        $I->sendPOST('api/v1/admin/tables/' . $table->_id . '/activate', ['version_id' => $published->data->_id]);
        $I->seeResponseCodeIs(403);
        $I->seeResponseContains('tables_activate');

        // The split of the active version is a production change.
        $split = ['variants_probability' => 'first', 'variants' => [['_id' => $table->variants[0]->_id, 'probability' => 100]]];
        $I->sendPUT($this->versionsUrl($table, $table->active_version_id, 'split'), $split);
        $I->seeResponseCodeIs(403);
        $I->seeResponseContains('tables_activate');
        $I->sendPUT($this->versionsUrl($table, $published->data->_id, 'split'), $split);
        $I->seeResponseCodeIs(200);

        $I->sendDELETE($this->versionsUrl($table, $published->data->_id));
        $I->seeResponseCodeIs(403);
        $I->seeResponseContains('tables_publish');
        $I->sendDELETE($this->versionsUrl($table, $draft->_id));
        $I->seeResponseCodeIs(200);
    }
}
