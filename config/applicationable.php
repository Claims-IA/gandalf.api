<?php
return [
    'middleware' => ['oauth', 'applicationable.acl'],
    'user_model' => 'App\Models\User',
    'routes' => [
        'prefix' => '/api/v1/',
        'applications' => '/projects',
        'current_application' => '/projects/current',
        'consumers' => '/projects/consumers',
        'users' => '/projects/users',
        'set_admin' => '/projects/users/admin',
    ],
    'required_scopes' => [
        'users' => [
            'tables_view',
        ],
    ],
    'scopes' => [
        'users' => [
            'tables_create',
            'tables_view',
            'tables_update',
            'tables_delete',
            'decisions_make',
            'consumers_get',
            'consumers_manage',
            'users_manage',
            'project_update',
            'decisions_view',
            // Table versions (VERSIONS.md): publishing and going to production
            // are separate rights.
            'tables_publish',
            'tables_activate',
            // Run an inactive published version (route v2). Also a user scope: a
            // user only gives an API key the scopes they hold.
            'decisions_make_inactive',
        ],
        'consumers' => [
            'decisions_view',
            'decisions_make',
            // "Make API call for inactive versions" (VERSIONS.md, batch 3)
            'decisions_make_inactive',
        ],
    ],
    'acl' => [
        'get' => [
            '~^\/api\/v1\/admin\/tables$~' => ['tables_view'],
            '~^\/api\/v1\/admin\/tables\/(.+)$~' => ['tables_view'],

            '~^\/api\/v1\/admin\/flows$~' => ['tables_view'],
            '~^\/api\/v1\/admin\/flows\/(.+)$~' => ['tables_view'],

            '~^\/api\/v1\/admin\/categories$~' => ['tables_view'],

            '~^\/api\/v1\/admin\/decisions$~' => ['decisions_view'],
            '~^\/api\/v1\/admin\/decisions\/(.+)$~' => ['decisions_view'],

            '~^\/api\/v1\/admin\/changelog\/tables\/(.+)$~' => ['tables_view'],

            '~^\/api\/v1\/decisions\/(.+)$~' => ['decisions_view'],

            '~^\/api\/v1\/admin\/changelog\/(.+)$~' => ['tables_view'],
            '~^\/api\/v1\/admin\/changelog\/(.+)\/(.+)$~' => ['tables_view'],
            '~^\/api\/v1\/admin\/changelog\/(.+)\/(.+)\/diff$~' => ['tables_view'],

            '~^\/api\/v1\/projects\/consumers~' => ['consumers_get'],
            '~^\/api\/v1\/projects\/current$~' => ['tables_view'],
            // The export holds every decision and change of the project.
            '~^\/api\/v1\/projects\/export$~' => ['project_update'],
            '~^\/api\/v1\/projects\/collaborators$~' => ['users_manage'],
            '~^\/api\/v1\/projects\/users$~' => ['tables_view'],
        ],
        'post' => [
            '~^\/api\/v1\/admin\/tables$~' => ['tables_create'],
            '~^\/api\/v1\/admin\/flows$~' => ['tables_create'],
            '~^\/api\/v1\/admin\/tables\/(.+)\/copy$~' => ['tables_create'],
            '~^\/api\/v1\/admin\/flows\/(.+)\/copy$~' => ['tables_create'],
            // Import creates (tables_create) or updates (tables_update) a table:
            // checked in TablesController::import once the kind is known.
            '~^\/api\/v1\/admin\/tables\/import$~' => ['tables_view'],
            // Cross-project copy/move (also gated on project-admin role in the
            // controller via CopyMoveGuard). Copy needs create; move additionally
            // removes from the source, so it needs delete too.
            '~^\/api\/v1\/admin\/tables\/(.+)\/copyto\/(.+)$~' => ['tables_create'],
            '~^\/api\/v1\/admin\/tables\/(.+)\/moveto\/(.+)$~' => ['tables_create', 'tables_delete'],
            '~^\/api\/v1\/admin\/flows\/(.+)\/copyto\/(.+)$~' => ['tables_create'],
            '~^\/api\/v1\/admin\/flows\/(.+)\/moveto\/(.+)$~' => ['tables_create', 'tables_delete'],
            // Table versions (VERSIONS.md)
            '~^\/api\/v1\/admin\/tables\/(.+)\/versions\/(.+)\/publish$~' => ['tables_publish'],
            // Test run: a published version needs decisions_make, as calling
            // it does; a draft also needs tables_update (checked by the controller).
            '~^\/api\/v1\/admin\/tables\/(.+)\/versions\/(.+)\/decisions$~' => ['tables_view', 'decisions_make'],
            '~^\/api\/v1\/admin\/tables\/(.+)\/versions$~' => ['tables_update'],
            '~^\/api\/v1\/admin\/tables\/(.+)\/activate$~' => ['tables_activate'],
            // + tables_publish / tables_activate for publish / activate, checked in
            // TableVersionsController::updateFlows
            '~^\/api\/v1\/admin\/tables\/(.+)\/flows\/update$~' => ['tables_update'],
            // Flow versions (VERSIONS.md, batch 4)
            '~^\/api\/v1\/admin\/flows\/(.+)\/versions\/(.+)\/publish$~' => ['tables_publish'],
            '~^\/api\/v1\/admin\/flows\/(.+)\/versions\/(.+)\/decisions$~' => ['tables_view', 'decisions_make'],
            '~^\/api\/v1\/admin\/flows\/(.+)\/versions$~' => ['tables_update'],
            '~^\/api\/v1\/admin\/flows\/(.+)\/activate$~' => ['tables_activate'],
            '~^\/api\/v1\/admin\/changelog\/(.+)\/(.+)\/rollback\/(.+)$~' => ['tables_update'],
            '~^\/api\/v1\/tables\/(.+)\/decisions$~' => ['decisions_make'],
            '~^\/api\/v1\/flows\/(.+)\/decisions$~' => ['decisions_make'],
            // An inactive version also needs decisions_make_inactive, checked in
            // ConsumerController::tableCheckV2 once the version is known.
            '~^\/api\/v2\/tables\/(.+)\/decisions$~' => ['decisions_make'],
            '~^\/api\/v2\/flows\/(.+)\/decisions$~' => ['decisions_make'],
            '~^\/api\/v1\/projects\/collaborators\/confirm$~' => ['users_manage'],
            '~^\/api\/v1\/projects\/collaborators\/account$~' => ['users_manage'],
            '~^\/api\/v1\/projects\/collaborators\/invitation\/resend$~' => ['users_manage'],
            '~^\/api\/v1\/invite$~' => ['users_manage'],
            '~^\/api\/v1\/projects\/users$~' => ['users_manage'],
            '~^\/api\/v1\/projects\/users\/admin$~' => ['users_manage'],
            '~^\/api\/v1\/projects\/consumers~' => ['consumers_manage'],
        ],
        'put' => [
            // Editing the category list mutates project-level settings, so it
            // requires project_update (project admin), not just tables_update.
            '~^\/api\/v1\/admin\/categories$~' => ['project_update'],
            // Changing the split of the active version also needs tables_activate,
            // checked in TableVersionsController::split. Before the generic rule.
            '~^\/api\/v1\/admin\/tables\/(.+)\/versions\/(.+)\/split$~' => ['tables_update'],
            '~^\/api\/v1\/admin\/tables\/(.+)$~' => ['tables_update'],
            '~^\/api\/v1\/admin\/flows\/(.+)$~' => ['tables_update'],
            '~^\/api\/v1\/admin\/decisions\/(.+)\/meta$~' => ['decisions_make'],
            '~^\/api\/v1\/projects\/consumers~' => ['consumers_manage'],
            '~^\/api\/v1\/projects\/users$~' => ['users_manage'],
            '~^\/api\/v1\/projects$~' => ['project_update'],
            '~^\/api\/v1\/projects\/(.+)$~' => ['project_update'],
        ],
        'delete' => [
            // Deleting a flow draft is editing; a published version also needs
            // tables_publish, checked in FlowVersionsController::delete. Before
            // the generic rule.
            '~^\/api\/v1\/admin\/flows\/(.+)\/versions\/(.+)$~' => ['tables_update'],
            '~^\/api\/v1\/admin\/flows\/(.+)$~' => ['tables_delete'],
            '~^\/api\/v1\/projects\/collaborators\/invitation$~' => ['users_manage'],
            '~^\/api\/v1\/projects\/collaborators\/account$~' => ['users_manage'],
            '~^\/api\/v1\/projects\/users$~' => ['users_manage'],
            '~^\/api\/v1\/projects\/consumers~' => ['consumers_manage'],
            // Deleting an application is reserved to superadmins: the
            // 'superadmin' route middleware checks it (ADMIN.md).
            '~^\/api\/v1\/projects$~' => [],
            // Deleting a draft is editing; deleting a published version also needs
            // tables_publish, checked in TableVersionsController::delete. Before the
            // generic rule.
            '~^\/api\/v1\/admin\/tables\/(.+)\/versions\/(.+)$~' => ['tables_update'],
            '~^\/api\/v1\/admin\/tables\/(.+)$~' => ['tables_delete'],
        ],
    ],
];
