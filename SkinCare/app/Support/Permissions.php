<?php

namespace App\Support;

final class Permissions
{
    public const ADMIN_DASHBOARD_VIEW = 'admin.dashboard.view';

    public const CATALOG_READ = 'catalog.read';

    public const CATALOG_WRITE = 'catalog.write';

    public const INVENTORY_READ = 'inventory.read';

    public const INVENTORY_WRITE = 'inventory.write';

    public const ORDERS_READ = 'orders.read';

    public const ORDERS_WRITE = 'orders.write';

    public const CUSTOMERS_READ = 'customers.read';

    public const DISCOUNTS_READ = 'discounts.read';

    public const DISCOUNTS_WRITE = 'discounts.write';

    public const AUDIT_READ = 'audit.read';

    public static function all(): array
    {
        return [
            self::ADMIN_DASHBOARD_VIEW,
            self::CATALOG_READ,
            self::CATALOG_WRITE,
            self::INVENTORY_READ,
            self::INVENTORY_WRITE,
            self::ORDERS_READ,
            self::ORDERS_WRITE,
            self::CUSTOMERS_READ,
            self::DISCOUNTS_READ,
            self::DISCOUNTS_WRITE,
            self::AUDIT_READ,
        ];
    }
}
