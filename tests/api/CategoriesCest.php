<?php

/**
 * CategoriesCest
 *
 * API coverage for per-application categories: reading and replacing the list,
 * validation (hex colour, required name, duplicate names), assigning a category
 * to a table and a flow, filtering the table/flow lists by category, and the
 * auto-cleanup that clears category_id from tables/flows when their category is
 * removed from the application's list.
 */
class CategoriesCest
{
    public function _before(ApiTester $I)
    {
        $I->dropDatabase();
        $I->haveHttpHeader('Content-Type', 'application/json');
    }

    /**
     * A fresh project starts with an empty category list.
     */
    public function emptyByDefault(ApiTester $I)
    {
        $I->createAndLoginUser();
        $I->createProjectAndSetHeader();

        $I->sendGET('api/v1/admin/categories');
        $I->seeResponseCodeIs(200);
        $I->assertEquals([], $I->getResponseFields()->data->categories);
    }

    /**
     * PUT replaces the list; the server assigns an id and normalises the colour,
     * and a subsequent GET returns exactly what was stored.
     */
    public function createAndReadList(ApiTester $I)
    {
        $I->createAndLoginUser();
        $I->createProjectAndSetHeader();

        $I->sendPUT('api/v1/admin/categories', [
            'categories' => [
                ['name' => 'Risque', 'color' => '#e11d48'],
                ['name' => 'Tarification', 'color' => '#2563EB'],
            ],
        ]);
        $I->seeResponseCodeIs(200);

        $categories = $I->getResponseFields()->data->categories;
        $I->assertEquals(2, count($categories));
        $I->assertEquals('Risque', $categories[0]->name);
        // Colour is stored upper-cased.
        $I->assertEquals('#E11D48', $categories[0]->color);
        $I->assertRegExp('/^cat_[0-9a-f]{16}$/', $categories[0]->id);
        $I->assertNotEquals($categories[0]->id, $categories[1]->id);

        // GET returns the same list.
        $I->sendGET('api/v1/admin/categories');
        $I->seeResponseCodeIs(200);
        $I->assertEquals(2, count($I->getResponseFields()->data->categories));
    }

    /**
     * Editing a category by id preserves that id (rename/recolour in place),
     * while entries without a known id receive a new one.
     */
    public function idPreservedOnUpdate(ApiTester $I)
    {
        $I->createAndLoginUser();
        $I->createProjectAndSetHeader();

        $I->sendPUT('api/v1/admin/categories', [
            'categories' => [['name' => 'Risque', 'color' => '#E11D48']],
        ]);
        $id = $I->getResponseFields()->data->categories[0]->id;

        // Rename + recolour the same id, add a brand new one.
        $I->sendPUT('api/v1/admin/categories', [
            'categories' => [
                ['id' => $id, 'name' => 'Risque élevé', 'color' => '#B91C1C'],
                ['name' => 'Nouvelle', 'color' => '#16A34A'],
            ],
        ]);
        $I->seeResponseCodeIs(200);
        $categories = $I->getResponseFields()->data->categories;
        $I->assertEquals($id, $categories[0]->id);
        $I->assertEquals('Risque élevé', $categories[0]->name);
        $I->assertRegExp('/^cat_[0-9a-f]{16}$/', $categories[1]->id);
        $I->assertNotEquals($id, $categories[1]->id);
    }

    /**
     * An unknown/foreign id supplied by the client is ignored and replaced by a
     * fresh generated id (a client cannot smuggle an arbitrary id in).
     */
    public function foreignIdIsReplaced(ApiTester $I)
    {
        $I->createAndLoginUser();
        $I->createProjectAndSetHeader();

        $I->sendPUT('api/v1/admin/categories', [
            'categories' => [['id' => 'cat_deadbeefdeadbeef', 'name' => 'X', 'color' => '#000000']],
        ]);
        $I->seeResponseCodeIs(200);
        $I->assertNotEquals(
            'cat_deadbeefdeadbeef',
            $I->getResponseFields()->data->categories[0]->id
        );
    }

    /**
     * A non-hex colour is rejected with 422.
     */
    public function rejectsInvalidColor(ApiTester $I)
    {
        $I->createAndLoginUser();
        $I->createProjectAndSetHeader();

        $I->sendPUT('api/v1/admin/categories', [
            'categories' => [['name' => 'Bad', 'color' => 'red']],
        ]);
        $I->seeResponseCodeIs(422);

        // 3-digit shorthand is also rejected (strict #RRGGBB).
        $I->sendPUT('api/v1/admin/categories', [
            'categories' => [['name' => 'Bad', 'color' => '#E14']],
        ]);
        $I->seeResponseCodeIs(422);
    }

    /**
     * A missing name is rejected with 422.
     */
    public function rejectsMissingName(ApiTester $I)
    {
        $I->createAndLoginUser();
        $I->createProjectAndSetHeader();

        $I->sendPUT('api/v1/admin/categories', [
            'categories' => [['color' => '#E11D48']],
        ]);
        $I->seeResponseCodeIs(422);
    }

    /**
     * Two categories with the same name (case-insensitive) are rejected.
     */
    public function rejectsDuplicateNames(ApiTester $I)
    {
        $I->createAndLoginUser();
        $I->createProjectAndSetHeader();

        $I->sendPUT('api/v1/admin/categories', [
            'categories' => [
                ['name' => 'Risque', 'color' => '#E11D48'],
                ['name' => 'risque', 'color' => '#2563EB'],
            ],
        ]);
        $I->seeResponseCodeIs(422);
    }

    /**
     * A category can be assigned to a table, is returned on read, and shows up
     * in the list projection; the table list can be filtered by category_id.
     */
    public function assignAndFilterTable(ApiTester $I)
    {
        $I->createAndLoginUser();
        $I->createProjectAndSetHeader();

        $I->sendPUT('api/v1/admin/categories', [
            'categories' => [
                ['name' => 'Risque', 'color' => '#E11D48'],
                ['name' => 'Autre', 'color' => '#2563EB'],
            ],
        ]);
        $categories = $I->getResponseFields()->data->categories;
        $catRisque = $categories[0]->id;
        $catAutre  = $categories[1]->id;

        // Table A -> Risque
        $dataA = $I->getTableShortData();
        $dataA['title'] = 'Table A';
        $dataA['category_id'] = $catRisque;
        $tableA = $I->createTable($dataA);
        $I->assertEquals($catRisque, $tableA->category_id);

        // Table B -> Autre
        $dataB = $I->getTableShortData();
        $dataB['title'] = 'Table B';
        $dataB['category_id'] = $catAutre;
        $I->createTable($dataB);

        // Reading table A back exposes category_id.
        $I->sendGET('api/v1/admin/tables/' . $tableA->_id);
        $I->seeResponseCodeIs(200);
        $I->assertEquals($catRisque, $I->getResponseFields()->data->category_id);

        // List projection carries category_id.
        $I->sendGET('api/v1/admin/tables');
        $I->seeResponseCodeIs(200);
        $I->seeResponseMatchesJsonType(['category_id' => 'string|null'], '$.data[*]');

        // Filter by category returns only Table A.
        $I->sendGET('api/v1/admin/tables?category_id=' . $catRisque);
        $I->seeResponseCodeIs(200);
        $list = $I->getResponseFields()->data;
        $I->assertEquals(1, count($list));
        $I->assertEquals('Table A', $list[0]->title);
    }

    /**
     * A category can be assigned to a flow and the flow list filtered by it.
     */
    public function assignAndFilterFlow(ApiTester $I)
    {
        $I->createAndLoginUser();
        $I->createProjectAndSetHeader();

        $I->sendPUT('api/v1/admin/categories', [
            'categories' => [['name' => 'Groupe A', 'color' => '#7C3AED']],
        ]);
        $catId = $I->getResponseFields()->data->categories[0]->id;

        $flow = $this->createFlow($I, ['category_id' => $catId, 'title' => 'Flow One']);
        $I->assertEquals($catId, $flow->category_id);

        // Uncategorised flow as a control.
        $this->createFlow($I, ['title' => 'Flow Two']);

        $I->sendGET('api/v1/admin/flows?category_id=' . $catId);
        $I->seeResponseCodeIs(200);
        $list = $I->getResponseFields()->data;
        $I->assertEquals(1, count($list));
        $I->assertEquals('Flow One', $list[0]->title);
        $I->seeResponseMatchesJsonType(['category_id' => 'string|null'], '$.data[*]');
    }

    /**
     * Removing a category from the list clears category_id from every table and
     * flow that referenced it (auto-cleanup), leaving other references intact.
     */
    public function removingCategoryCleansUpReferences(ApiTester $I)
    {
        $I->createAndLoginUser();
        $I->createProjectAndSetHeader();

        $I->sendPUT('api/v1/admin/categories', [
            'categories' => [
                ['name' => 'Keep', 'color' => '#16A34A'],
                ['name' => 'Drop', 'color' => '#E11D48'],
            ],
        ]);
        $categories = $I->getResponseFields()->data->categories;
        $keepId = $categories[0]->id;
        $dropId = $categories[1]->id;

        // A table and a flow reference the category we are about to drop.
        $dataDrop = $I->getTableShortData();
        $dataDrop['title'] = 'Dropped table';
        $dataDrop['category_id'] = $dropId;
        $tableDrop = $I->createTable($dataDrop);

        $dataKeep = $I->getTableShortData();
        $dataKeep['title'] = 'Kept table';
        $dataKeep['category_id'] = $keepId;
        $tableKeep = $I->createTable($dataKeep);

        $flowDrop = $this->createFlow($I, ['category_id' => $dropId, 'title' => 'Dropped flow']);

        // Drop the second category (keep only "Keep").
        $I->sendPUT('api/v1/admin/categories', [
            'categories' => [['id' => $keepId, 'name' => 'Keep', 'color' => '#16A34A']],
        ]);
        $I->seeResponseCodeIs(200);

        // The dropped references are now null.
        $I->sendGET('api/v1/admin/tables/' . $tableDrop->_id);
        $I->assertNull($I->getResponseFields()->data->category_id);

        $I->sendGET('api/v1/admin/flows/' . $flowDrop->_id);
        $I->assertNull($I->getResponseFields()->data->category_id);

        // The kept reference is untouched.
        $I->sendGET('api/v1/admin/tables/' . $tableKeep->_id);
        $I->assertEquals($keepId, $I->getResponseFields()->data->category_id);
    }

    /**
     * A table cannot be assigned a category_id that is not a string.
     */
    public function rejectsInvalidCategoryIdOnTable(ApiTester $I)
    {
        $I->createAndLoginUser();
        $I->createProjectAndSetHeader();

        $data = $I->getTableShortData();
        $data['category_id'] = ['not' => 'a string'];
        $I->sendPOST('api/v1/admin/tables', $data);
        $I->seeResponseCodeIs(422);
    }

    /**
     * Build a minimal, valid single-node flow whose only field is fed by a flow
     * input, and return the created flow document.
     *
     * @param  ApiTester $I
     * @param  array     $overrides  Merged over the base payload (e.g. title, category_id).
     * @return object
     */
    private function createFlow(ApiTester $I, array $overrides = [])
    {
        // A one-field table so the flow graph is trivial to wire and validate.
        $tableData = $I->getTableShortData();
        $tableData['title'] = 'Flow node table ' . uniqid();
        $tableData['fields'] = [
            [
                '_id'    => $I->getMongoId(),
                'key'    => 'score',
                'title'  => 'score',
                'source' => 'request',
                'type'   => 'numeric',
                'preset' => null,
            ],
        ];
        // Rebuild rules to reference only the single 'score' field.
        $tableData['variants'][0]['rules'] = [
            [
                '_id'         => $I->getMongoId(),
                'than'        => 'Approve',
                'title'       => 'Rule',
                'description' => 'Rule description',
                'conditions'  => [
                    [
                        '_id'       => $I->getMongoId(),
                        'field_key' => 'score',
                        'condition' => '$eq',
                        'value'     => true,
                    ],
                ],
            ],
        ];
        $table = $I->createTable($tableData);

        $payload = array_merge([
            'title'   => 'Flow ' . uniqid(),
            'inputs'  => [['key' => 'score', 'type' => 'numeric']],
            'outputs' => [['name' => 'result', 'from_node' => 'n1', 'from_output' => 'final_decision']],
            'nodes'   => [['node_id' => 'n1', 'table_id' => $table->_id]],
            'edges'   => [
                [
                    'from' => ['input' => 'score'],
                    'into' => ['node' => 'n1', 'field' => 'score'],
                ],
            ],
        ], $overrides);

        $I->sendPOST('api/v1/admin/flows', $payload);
        $I->seeResponseCodeIs(201);

        return $I->getResponseFields()->data;
    }
}
