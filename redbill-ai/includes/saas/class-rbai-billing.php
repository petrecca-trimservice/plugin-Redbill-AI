<?php
/**
 * Gestione piani e feature gate.
 * Wrapper minimale – predisposto per integrazione Freemius.
 */

if (!defined('ABSPATH')) {
    exit;
}

class RBAI_Billing {

    /** Feature disponibili per piano */
    private static array $plan_features = [
        'basic' => [
            'gemini_ai'      => false,
            'email_analysis' => false,
            'max_invoices'   => 500,
        ],
        'pro' => [
            'gemini_ai'      => true,
            'email_analysis' => true,
            'max_invoices'   => 0, // illimitato
        ],
        'enterprise' => [
            'gemini_ai'      => true,
            'email_analysis' => true,
            'max_invoices'   => 0,
            'api_access'     => true,
        ],
    ];

    /**
     * Verifica se il tenant ha accesso a una feature.
     */
    public static function tenant_has_feature(RBAI_Tenant $tenant, string $feature): bool {
        $plan     = $tenant->get_plan();
        $features = self::$plan_features[$plan] ?? self::$plan_features['basic'];
        return !empty($features[$feature]);
    }

    /**
     * Restituisce il piano del tenant.
     */
    public static function get_plan(RBAI_Tenant $tenant): string {
        return $tenant->get_plan();
    }

    /**
     * Restituisce il numero massimo di fatture (0 = illimitato).
     */
    public static function get_max_invoices(RBAI_Tenant $tenant): int {
        $plan     = $tenant->get_plan();
        $features = self::$plan_features[$plan] ?? self::$plan_features['basic'];
        return (int) ($features['max_invoices'] ?? 500);
    }

    /**
     * Restituisce tutte le feature del piano come array.
     */
    public static function get_plan_features(string $plan): array {
        return self::$plan_features[$plan] ?? self::$plan_features['basic'];
    }
}
