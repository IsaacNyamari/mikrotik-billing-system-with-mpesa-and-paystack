<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Strict Tenant Scoping
    |--------------------------------------------------------------------------
    |
    | When strict scoping is enabled, querying or creating a tenant-owned model
    | without a resolved tenant throws instead of silently operating across
    | every tenant. Code that legitimately needs to span tenants — seeders,
    | console commands, platform super admin screens — must say so explicitly
    | through Tenancy::withoutScope() or Tenancy::forTenant().
    |
    | Leaving this disabled means a forgotten tenant context leaks data between
    | ISPs without raising anything. It should stay enabled.
    |
    */

    'strict' => env('TENANCY_STRICT', true),

    /*
    |--------------------------------------------------------------------------
    | Tenant Foreign Key
    |--------------------------------------------------------------------------
    |
    | The column used on every tenant-owned table to record ownership.
    |
    */

    'foreign_key' => 'tenant_id',

];
