<?php

/*
 * Permission catalogue and default role map (spec §4).
 * Slugs are stable; add new ones, never rename. `*` grants every permission
 * in the catalogue (Tenant Owner only).
 */
return [

    'permissions' => [
        'dashboard.view',
        'products.view', 'products.create', 'products.update', 'products.delete', 'products.import',
        'products.images.view', 'products.images.manage',
        'products.costs.view',
        'inventory.view', 'inventory.adjust',
        'orders.view', 'orders.create', 'orders.update', 'orders.cancel', 'orders.import',
        'customers.view', 'customers.manage',
        'shipments.view', 'shipments.manage',
        'integrations.manage',
        'accounting.view', 'accounting.post', 'accounting.period_close',
        'expenses.view', 'expenses.manage',
        'reports.view', 'profit.view',
        'staff.manage', 'roles.manage',
        'settings.manage',
        'subscription.manage',
        'audit.view',
    ],

    'roles' => [
        'owner' => [
            'name' => 'Tenant Owner',
            'permissions' => ['*'],
        ],
        'admin' => [
            'name' => 'Admin',
            'permissions' => [
                'dashboard.view',
                'products.view', 'products.create', 'products.update', 'products.delete', 'products.import',
                'products.images.view', 'products.images.manage', 'products.costs.view',
                'inventory.view', 'inventory.adjust',
                'orders.view', 'orders.create', 'orders.update', 'orders.cancel', 'orders.import',
                'customers.view', 'customers.manage',
                'shipments.view', 'shipments.manage',
                'integrations.manage',
                'accounting.view', 'accounting.post',
                'expenses.view', 'expenses.manage',
                'reports.view', 'profit.view',
                'staff.manage', 'settings.manage', 'audit.view',
            ],
        ],
        'manager' => [
            'name' => 'Manager',
            'permissions' => [
                'dashboard.view',
                'products.view', 'products.create', 'products.update', 'products.import',
                'products.images.view', 'products.images.manage',
                'inventory.view', 'inventory.adjust',
                'orders.view', 'orders.create', 'orders.update', 'orders.cancel', 'orders.import',
                'customers.view', 'customers.manage',
                'shipments.view', 'shipments.manage',
                'reports.view',
            ],
        ],
        'order_staff' => [
            'name' => 'Order Staff',
            'permissions' => [
                'dashboard.view',
                'products.view', 'products.images.view',
                'orders.view', 'orders.create', 'orders.update',
                'customers.view', 'customers.manage',
                'shipments.view',
            ],
        ],
        'inventory_staff' => [
            'name' => 'Inventory Staff',
            'permissions' => [
                'dashboard.view',
                'products.view', 'products.create', 'products.update',
                'products.images.view', 'products.images.manage',
                'inventory.view', 'inventory.adjust',
            ],
        ],
        'accounts_staff' => [
            'name' => 'Accounts Staff',
            'permissions' => [
                'dashboard.view',
                'accounting.view', 'accounting.post',
                'expenses.view', 'expenses.manage',
                'reports.view', 'profit.view',
            ],
        ],
        'delivery_staff' => [
            'name' => 'Delivery Staff',
            'permissions' => [
                'dashboard.view',
                'orders.view', 'products.images.view',
                'shipments.view', 'shipments.manage',
            ],
        ],
        'viewer' => [
            'name' => 'Viewer',
            'permissions' => [
                'dashboard.view',
                'products.view', 'products.images.view',
                'inventory.view', 'orders.view', 'customers.view', 'shipments.view',
            ],
        ],
    ],
];
