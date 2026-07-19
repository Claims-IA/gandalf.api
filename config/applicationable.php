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
            'project_delete',
            'decisions_view',
        ],
        'consumers' => [
            'decisions_view',
            'decisions_make',
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
            '~^\/api\/v1\/projects\/collaborators$~' => ['users_manage'],
            '~^\/api\/v1\/projects\/users$~' => ['tables_view'],
        ],
        'post' => [
            '~^\/api\/v1\/admin\/tables$~' => ['tables_create'],
            '~^\/api\/v1\/admin\/flows$~' => ['tables_create'],
            '~^\/api\/v1\/admin\/tables\/(.+)\/copy$~' => ['tables_create'],
            // Cross-project copy/move (also gated on project-admin role in the
            // controller via CopyMoveGuard). Copy needs create; move additionally
            // removes from the source, so it needs delete too.
            '~^\/api\/v1\/admin\/tables\/(.+)\/copyto\/(.+)$~' => ['tables_create'],
            '~^\/api\/v1\/admin\/tables\/(.+)\/moveto\/(.+)$~' => ['tables_create', 'tables_delete'],
            '~^\/api\/v1\/admin\/flows\/(.+)\/copyto\/(.+)$~' => ['tables_create'],
            '~^\/api\/v1\/admin\/flows\/(.+)\/moveto\/(.+)$~' => ['tables_create', 'tables_delete'],
            '~^\/api\/v1\/admin\/changelog\/(.+)\/(.+)\/rollback\/(.+)$~' => ['tables_update'],
            '~^\/api\/v1\/tables\/(.+)\/decisions$~' => ['decisions_make'],
            '~^\/api\/v1\/flows\/(.+)\/decisions$~' => ['decisions_make'],
            '~^\/api\/v1\/projects\/collaborators\/confirm$~' => ['users_manage'],
            '~^\/api\/v1\/projects\/collaborators\/invitation\/resend$~' => ['users_manage'],
            '~^\/api\/v1\/projects\/users$~' => ['users_manage'],
            '~^\/api\/v1\/projects\/consumers~' => ['consumers_manage'],
        ],
        'put' => [
            // Editing the category list mutates project-level settings, so it
            // requires project_update (project admin), not just tables_update.
            '~^\/api\/v1\/admin\/categories$~' => ['project_update'],
            '~^\/api\/v1\/admin\/tables\/(.+)$~' => ['tables_update'],
            '~^\/api\/v1\/admin\/flows\/(.+)$~' => ['tables_update'],
            '~^\/api\/v1\/projects\/consumers~' => ['consumers_manage'],
            '~^\/api\/v1\/projects\/users$~' => ['users_manage'],
            '~^\/api\/v1\/projects\/(.+)$~' => ['project_update'],
        ],
        'delete' => [
            '~^\/api\/v1\/admin\/flows\/(.+)$~' => ['tables_delete'],
            '~^\/api\/v1\/projects\/collaborators\/invitation$~' => ['users_manage'],
            '~^\/api\/v1\/projects\/collaborators\/account$~' => ['users_manage'],
            '~^\/api\/v1\/projects\/users$~' => ['users_manage'],
            '~^\/api\/v1\/projects\/consumers~' => ['consumers_manage'],
            '~^\/api\/v1\/projects$~' => ['project_delete'],
            '~^\/api\/v1\/admin\/tables\/(.+)$~' => ['tables_delete'],
        ],
    ],
];
