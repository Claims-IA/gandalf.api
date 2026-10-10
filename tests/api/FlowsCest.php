<?php
/**
 * FlowsCest
 *
 * The first API tests of flows (Decision Requirement Graphs), written as the
 * safety net batch 4 of table versioning starts with (VERSIONS.md): graph
 * validation, runs, then flow versions pinned to table versions. Run only
 * against a disposable HTTP and MongoDB environment (the suite drops its
 * database), with the compatibility mode on (the default).
 */

class FlowsCest
{
    private function createAgeTable(ApiTester $I, $threshold = 18)
    {
        return $I->createTable([
            'title' => 'Age check',
            'description' => 'Flow test table',
            'matching_type' => 'first',
            'decision_type' => 'string',
            'variants_probability' => 'first',
            'fields' => [['key' => 'age', 'title' => 'Age', 'type' => 'numeric', 'source' => 'request', 'preset' => null]],
            'variants' => [[
                'title' => 'Main',
                'default_decision' => 'NON',
                'rules' => [[
                    'than' => 'OUI', 'title' => 'Adult', 'description' => '',
                    'conditions' => [['field_key' => 'age', 'condition' => '$gte', 'value' => $threshold]],
                ]],
            ]],
        ]);
    }

    private function flowBody($tableId)
    {
        return [
            'title' => 'Age flow',
            'inputs' => [['key' => 'age', 'type' => 'numeric']],
            'nodes' => [['node_id' => 'n1', 'table_id' => $tableId]],
            'edges' => [],
            'outputs' => [['name' => 'decision', 'from_node' => 'n1', 'from_output' => 'final_decision']],
        ];
    }

    private function createFlow(ApiTester $I, $tableId)
    {
        $I->sendPOST('api/v1/admin/flows', $this->flowBody($tableId));
        $I->seeResponseCodeIs(201);

        return $I->getResponseFields()->data;
    }

    private function run(ApiTester $I, $flowId, $age)
    {
        $I->sendPOST("api/v1/flows/$flowId/decisions", ['age' => $age]);
        $I->seeResponseCodeIs(200);

        return $I->getResponseFields()->data;
    }

    public function aFlowRunsItsTables(ApiTester $I)
    {
        $I->createAndLoginUser();
        $I->createProjectAndSetHeader();
        $table = $this->createAgeTable($I);
        $flow = $this->createFlow($I, $table->_id);

        $run = $this->run($I, $flow->_id, 20);
        $I->assertEquals('OUI', $run->answer->decision);
        $I->assertEquals('drg', $run->decision_kind);
        $I->assertNotEmpty($run->flow_run_id);

        $I->sendGET("api/v1/admin/flows/{$flow->_id}/runs");
        $I->seeResponseCodeIs(200);
        $I->assertCount(1, $I->getResponseFields()->data);
    }

    public function anInvalidGraphIsRefused(ApiTester $I)
    {
        $I->createAndLoginUser();
        $I->createProjectAndSetHeader();
        $table = $this->createAgeTable($I);

        $body = $this->flowBody($table->_id);
        $body['inputs'] = [];
        $I->sendPOST('api/v1/admin/flows', $body);
        $I->seeResponseCodeIs(422);
        $I->seeResponseContains('is not fed by any edge or flow input');

        $body = $this->flowBody($I->getMongoId());
        $I->sendPOST('api/v1/admin/flows', $body);
        $I->seeResponseCodeIs(422);
        $I->seeResponseContains('does not exist in this project');
    }

    public function aPartialUpdateKeepsTheGraph(ApiTester $I)
    {
        $I->createAndLoginUser();
        $I->createProjectAndSetHeader();
        $table = $this->createAgeTable($I);
        $flow = $this->createFlow($I, $table->_id);

        $I->sendPUT("api/v1/admin/flows/{$flow->_id}", ['title' => 'Renamed']);
        $I->seeResponseCodeIs(200);
        $I->assertEquals('Renamed', $I->getResponseFields()->data->title);
        $I->assertCount(1, $I->getResponseFields()->data->nodes);
    }

    public function aFlowIsPinnedAndFollowsInCompatibilityMode(ApiTester $I)
    {
        $I->createAndLoginUser();
        $I->createProjectAndSetHeader();
        $table = $this->createAgeTable($I);
        $flow = $this->createFlow($I, $table->_id);
        $I->assertNotEmpty($flow->active_version_id);
        $I->assertEquals($table->active_version_id, $flow->nodes[0]->table_version_id);

        $run = $this->run($I, $flow->_id, 20);
        $I->assertEquals(1, $run->flow->version->number);
        $I->assertEquals(1, $run->nodes[0]->table_version->number);

        // The compatibility mode publishes a table version and the flow follows.
        $I->sendGET("api/v1/admin/tables/{$table->_id}");
        $data = json_decode(json_encode($I->getResponseFields()->data), true);
        $data['variants'][0]['rules'][0]['conditions'][0]['value'] = 21;
        unset($data['_id'], $data['applications'], $data['active_version_id']);
        $I->sendPUT("api/v1/admin/tables/{$table->_id}", $data);
        $I->seeResponseCodeIs(200);

        $run = $this->run($I, $flow->_id, 20);
        $I->assertEquals('NON', $run->answer->decision);
        $I->assertEquals(2, $run->flow->version->number);
        $I->assertEquals(2, $run->nodes[0]->table_version->number);

        // Version 1 of the table is still pinned by flow version 1.
        $I->sendDELETE("api/v1/admin/tables/{$table->_id}/versions/{$table->active_version_id}");
        $I->seeResponseCodeIs(409);
        $I->seeResponseContains('version_in_use');
    }

    public function aFlowDraftPinsAnOlderTableVersion(ApiTester $I)
    {
        $I->createAndLoginUser();
        $I->createProjectAndSetHeader();
        $table = $this->createAgeTable($I);
        $flow = $this->createFlow($I, $table->_id);
        $version1 = $flow->active_version_id;

        $I->sendGET("api/v1/admin/tables/{$table->_id}");
        $data = json_decode(json_encode($I->getResponseFields()->data), true);
        $data['variants'][0]['rules'][0]['conditions'][0]['value'] = 21;
        unset($data['_id'], $data['applications'], $data['active_version_id']);
        $I->sendPUT("api/v1/admin/tables/{$table->_id}", $data);

        $I->sendPOST("api/v1/admin/flows/{$flow->_id}/versions", ['from_version_id' => $version1, 'name' => 'Old rules']);
        $I->seeResponseCodeIs(201);
        $draft = $I->getResponseFields()->data;
        $I->assertEquals($table->active_version_id, $draft->nodes[0]->table_version_id);

        $I->sendPOST("api/v1/admin/flows/{$flow->_id}/versions/{$draft->_id}/decisions", ['data' => ['age' => 20]]);
        $I->seeResponseCodeIs(200);
        $I->assertEquals('OUI', $I->getResponseFields()->data->answer->decision);

        $I->sendPOST("api/v1/admin/flows/{$flow->_id}/versions/{$draft->_id}/publish");
        $I->seeResponseCodeIs(200);
        $I->seeResponseContains('version_diverges');
        $I->sendPOST("api/v1/admin/flows/{$flow->_id}/activate", ['version_id' => $draft->_id]);
        $I->seeResponseCodeIs(200);
        $I->assertEquals('OUI', $this->run($I, $flow->_id, 20)->answer->decision);

        $I->sendDELETE("api/v1/admin/flows/{$flow->_id}/versions/{$draft->_id}");
        $I->seeResponseCodeIs(409);
    }
}
