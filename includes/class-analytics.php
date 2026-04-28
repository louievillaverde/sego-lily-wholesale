<?php
/**
 * Analytics — Cross-page funnel attribution + per-page heatmap intelligence.
 *
 * Tab 1: Funnels & Attribution
 *   Source attribution, channel comparison, funnel drop-off, time-to-purchase, revenue by source.
 *   Pulls from WooCommerce orders, wp_slw_applications, wp_slw_leads, user meta.
 *
 * Tab 2: Page Intelligence
 *   Clarity heatmap embed per page, auto-detects pages with [slw_*] shortcodes,
 *   page-specific conversion metrics.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SLW_Analytics {

    public static function init() {
        // Menu registered by SLW_Admin_Menu
    }

    /**
     * Render the analytics page.
     */
    public static function render_page() {
        $tab = sanitize_text_field( $_GET['tab'] ?? 'funnels' );
        $clarity_id = get_option( 'slw_clarity_project_id', '' );
        ?>
        <div class="wrap slw-admin-dashboard">
            <h1 class="slw-admin-dashboard__title">Analytics</h1>
            <p class="slw-admin-dashboard__subtitle">Funnel performance, source attribution, and page-level intelligence</p>

            <!-- Tabs -->
            <nav class="nav-tab-wrapper" style="margin-bottom:24px;">
                <a href="?page=slw-analytics&tab=funnels" class="nav-tab <?php echo $tab === 'funnels' ? 'nav-tab-active' : ''; ?>">Funnels &amp; Attribution</a>
                <a href="?page=slw-analytics&tab=pages" class="nav-tab <?php echo $tab === 'pages' ? 'nav-tab-active' : ''; ?>">Page Intelligence</a>
            </nav>

            <?php
            if ( $tab === 'pages' ) {
                self::render_page_intelligence( $clarity_id );
            } else {
                self::render_funnels();
            }
            ?>
        </div>
        <?php
    }

    // ─────────────────────────────────────────────────────────────
    // TAB 1: FUNNELS & ATTRIBUTION
    // ─────────────────────────────────────────────────────────────

    private static function render_funnels() {
        global $wpdb;

        $period      = sanitize_text_field( $_GET['period'] ?? '30' );
        $period_days = max( 7, min( 365, (int) $period ) );
        $since       = gmdate( 'Y-m-d', strtotime( "-{$period_days} days" ) );

        // Period selector
        ?>
        <div style="margin-bottom:24px;">
            <strong style="margin-right:8px;">Period:</strong>
            <?php foreach ( array( 7 => '7 days', 30 => '30 days', 90 => '90 days', 365 => '1 year' ) as $d => $label ) : ?>
                <a href="?page=slw-analytics&tab=funnels&period=<?php echo $d; ?>" class="button <?php echo (int) $period === $d ? 'button-primary' : ''; ?>" style="margin-right:4px;"><?php echo esc_html( $label ); ?></a>
            <?php endforeach; ?>
        </div>
        <?php

        // Gather all the data
        $sources = self::get_source_attribution( $since, $period_days );
        $ttp     = self::get_time_to_purchase( $since );

        // ── Source Attribution — 2×2 Quadrant ──
        ?>
        <div class="slw-analytics-section">
            <h2 style="font-size:18px;margin:0 0 4px;">Source Attribution</h2>
            <p style="color:#628393;font-size:13px;margin:0 0 20px;">Where your leads and revenue come from</p>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:24px;">
                <?php foreach ( $sources as $source ) : ?>
                <div class="slw-admin-card" style="margin:0;">
                    <h3 style="font-size:15px;font-weight:700;color:#1c2b2f;margin:0 0 16px;border-bottom:2px solid <?php echo esc_attr( $source['color'] ); ?>;padding-bottom:8px;">
                        <?php echo esc_html( $source['label'] ); ?>
                    </h3>
                    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;">
                        <div>
                            <span class="slw-analytics-stat-num"><?php echo esc_html( $source['leads'] ); ?></span>
                            <span class="slw-analytics-stat-label">Leads</span>
                        </div>
                        <div>
                            <span class="slw-analytics-stat-num"><?php echo esc_html( $source['purchases'] ); ?></span>
                            <span class="slw-analytics-stat-label">Purchases</span>
                        </div>
                        <div>
                            <span class="slw-analytics-stat-num"><?php echo esc_html( $source['conversion_rate'] ); ?>%</span>
                            <span class="slw-analytics-stat-label">Conversion</span>
                        </div>
                    </div>
                    <?php if ( $source['revenue'] > 0 ) : ?>
                    <div style="margin-top:12px;">
                        <span class="slw-analytics-stat-num"><?php echo wp_kses_post( wc_price( $source['revenue'] ) ); ?></span>
                        <span class="slw-analytics-stat-label">Revenue</span>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Time to Purchase -->
        <div class="slw-analytics-section">
            <h2 style="font-size:18px;margin:0 0 4px;">Time to Purchase</h2>
            <p style="color:#628393;font-size:13px;margin:0 0 20px;">How long from first touch to first order</p>

            <div class="slw-admin-stats" style="max-width:600px;">
                <div class="slw-admin-stats__card slw-admin-stats__card--teal">
                    <span class="slw-admin-stats__number"><?php echo esc_html( $ttp['avg_days'] ); ?></span>
                    <span class="slw-admin-stats__label">Avg days to purchase</span>
                </div>
                <div class="slw-admin-stats__card slw-admin-stats__card--green">
                    <span class="slw-admin-stats__number"><?php echo esc_html( $ttp['median_days'] ); ?></span>
                    <span class="slw-admin-stats__label">Median days</span>
                </div>
                <div class="slw-admin-stats__card slw-admin-stats__card--gold">
                    <span class="slw-admin-stats__number"><?php echo esc_html( $ttp['same_day_pct'] ); ?>%</span>
                    <span class="slw-admin-stats__label">Purchase same day</span>
                </div>
            </div>
        </div>


        <style>
            .slw-analytics-section { background:#fff; border:1px solid #dde8ed; border-radius:12px; padding:28px; margin-bottom:20px; }
            .slw-analytics-stat-num { display:block; font-size:18px; font-weight:800; color:#2c4f5e; }
            .slw-analytics-stat-label { display:block; font-size:10px; color:#6a8fa0; text-transform:uppercase; letter-spacing:.06em; font-weight:600; }
        </style>
        <?php
    }

    // ─────────────────────────────────────────────────────────────
    // TAB 2: PAGE INTELLIGENCE
    // ─────────────────────────────────────────────────────────────

    private static function render_page_intelligence( $clarity_id ) {
        $pages = self::get_tracked_pages();
        $selected = sanitize_text_field( $_GET['page_slug'] ?? '' );
        if ( ! $selected && ! empty( $pages ) ) {
            $selected = $pages[0]['slug'];
        }

        $selected_page = null;
        foreach ( $pages as $p ) {
            if ( $p['slug'] === $selected ) {
                $selected_page = $p;
                break;
            }
        }

        ?>
        <!-- Page selector -->
        <div style="margin-bottom:24px;">
            <strong style="margin-right:8px;">Page:</strong>
            <?php foreach ( $pages as $p ) : ?>
                <a href="?page=slw-analytics&tab=pages&page_slug=<?php echo esc_attr( $p['slug'] ); ?>"
                   class="button <?php echo $selected === $p['slug'] ? 'button-primary' : ''; ?>"
                   style="margin-right:4px;">
                    <?php echo esc_html( $p['title'] ); ?>
                </a>
            <?php endforeach; ?>
        </div>

        <?php if ( $selected_page ) : ?>

        <!-- Page-specific conversion metric -->
        <div class="slw-analytics-section">
            <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:16px;">
                <div>
                    <h2 style="font-size:18px;margin:0 0 4px;"><?php echo esc_html( $selected_page['title'] ); ?></h2>
                    <p style="color:#628393;font-size:13px;margin:0;">
                        Key metric: <strong><?php echo esc_html( $selected_page['metric_label'] ); ?></strong>
                    </p>
                </div>
                <div style="text-align:right;">
                    <span style="font-size:32px;font-weight:800;color:#2c4f5e;"><?php echo esc_html( $selected_page['metric_value'] ); ?></span>
                    <span style="display:block;font-size:11px;color:#6a8fa0;text-transform:uppercase;letter-spacing:.06em;font-weight:600;"><?php echo esc_html( $selected_page['metric_label'] ); ?></span>
                </div>
            </div>
        </div>

        <!-- Clarity Dashboard Link -->
        <?php if ( $clarity_id ) : ?>
        <div class="slw-analytics-section">
            <h2 style="font-size:18px;margin:0 0 4px;">Heatmap &amp; Session Recordings</h2>
            <p style="color:#628393;font-size:13px;margin:0 0 20px;">
                Powered by Microsoft Clarity. View heatmaps, session recordings, and scroll depth for this page.
            </p>
            <div style="display:flex;gap:12px;flex-wrap:wrap;">
                <a href="https://clarity.microsoft.com/projects/view/<?php echo esc_attr( $clarity_id ); ?>/dashboard" target="_blank" class="button button-primary" style="display:inline-flex;align-items:center;gap:6px;">
                    <span class="dashicons dashicons-chart-area" style="font-size:16px;width:16px;height:16px;line-height:16px;"></span>
                    Open Clarity Dashboard
                </a>
                <a href="https://clarity.microsoft.com/projects/view/<?php echo esc_attr( $clarity_id ); ?>/heatmaps?date=Last%207%20days&filter=url%3A<?php echo rawurlencode( '/' . $selected_page['slug'] . '/' ); ?>" target="_blank" class="button" style="display:inline-flex;align-items:center;gap:6px;">
                    <span class="dashicons dashicons-visibility" style="font-size:16px;width:16px;height:16px;line-height:16px;"></span>
                    View Heatmap for <?php echo esc_html( $selected_page['title'] ); ?>
                </a>
                <a href="https://clarity.microsoft.com/projects/view/<?php echo esc_attr( $clarity_id ); ?>/recordings?date=Last%207%20days&filter=url%3A<?php echo rawurlencode( '/' . $selected_page['slug'] . '/' ); ?>" target="_blank" class="button" style="display:inline-flex;align-items:center;gap:6px;">
                    <span class="dashicons dashicons-controls-play" style="font-size:16px;width:16px;height:16px;line-height:16px;"></span>
                    Session Recordings
                </a>
            </div>
        </div>
        <?php else : ?>
        <div class="slw-analytics-section">
            <p style="color:#628393;">Clarity project ID not configured. Add it under <strong>Wholesale &rarr; Settings</strong>.</p>
        </div>
        <?php endif; ?>

        <?php endif; ?>
        <?php
    }

    // ─────────────────────────────────────────────────────────────
    // DATA QUERIES
    // ─────────────────────────────────────────────────────────────

    /**
     * Get source attribution — leads, purchases, revenue by source.
     */
    private static function get_source_attribution( $since, $period_days ) {
        global $wpdb;
        $leads_table = $wpdb->prefix . 'slw_leads';
        $apps_table  = $wpdb->prefix . 'slw_applications';

        $source_config = array(
            'website'         => array( 'label' => 'Website Application', 'color' => '#2C4F5E' ),
            'shortcode'       => array( 'label' => 'Website Form', 'color' => '#386174' ),
            'wholesale_booth' => array( 'label' => 'Trade Show (Wholesale)', 'color' => '#D4AF37' ),
            'retail_booth'    => array( 'label' => 'Trade Show (Retail)', 'color' => '#B8892E' ),
        );

        $sources = array();

        foreach ( $source_config as $source_key => $config ) {
            // Count leads from slw_leads table
            $lead_count = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$leads_table} WHERE source = %s AND captured_at >= %s",
                $source_key, $since
            ) );

            // Count retail booth leads from user meta
            if ( $source_key === 'retail_booth' ) {
                $lead_count += (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = 'slw_booth_source' AND meta_value = %s",
                    'retail_booth'
                ) );
            }

            // Count applications from this source
            if ( $source_key === 'website' ) {
                $app_count = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$apps_table} WHERE submitted_at >= %s AND how_heard NOT LIKE '%%Trade show%%'",
                    $since
                ) );
                $lead_count += $app_count;
            }

            // Revenue from WooCommerce orders
            $purchases = 0;
            $revenue   = 0;

            if ( function_exists( 'wc_get_orders' ) && $lead_count > 0 ) {
                // This is an approximation — we'd need proper UTM tracking for exact attribution
                // For now, count orders from users that have the matching source meta
                if ( in_array( $source_key, array( 'retail_booth', 'wholesale_booth' ), true ) ) {
                    $user_ids = $wpdb->get_col( $wpdb->prepare(
                        "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = 'slw_booth_source' AND meta_value = %s",
                        $source_key
                    ) );
                    if ( ! empty( $user_ids ) ) {
                        $orders = wc_get_orders( array(
                            'customer_id' => $user_ids,
                            'date_after'  => $since,
                            'status'      => array( 'completed', 'processing' ),
                            'limit'       => -1,
                            'return'      => 'ids',
                        ) );
                        $purchases = count( $orders );
                        foreach ( $orders as $oid ) {
                            $order = wc_get_order( $oid );
                            if ( $order ) $revenue += (float) $order->get_total();
                        }
                    }
                }
            }

            $conversion_rate = $lead_count > 0 ? round( ( $purchases / $lead_count ) * 100, 1 ) : 0;

            $sources[] = array(
                'key'             => $source_key,
                'label'           => $config['label'],
                'color'           => $config['color'],
                'leads'           => $lead_count,
                'purchases'       => $purchases,
                'revenue'         => $revenue,
                'conversion_rate' => $conversion_rate,
            );
        }

        return $sources;
    }

    /**
     * Get time-to-purchase metrics.
     */
    private static function get_time_to_purchase( $since ) {
        global $wpdb;

        $days = array();

        if ( function_exists( 'wc_get_orders' ) ) {
            $orders = wc_get_orders( array(
                'date_after' => $since,
                'status'     => array( 'completed', 'processing' ),
                'limit'      => 100,
            ) );

            foreach ( $orders as $order ) {
                $user_id = $order->get_customer_id();
                if ( ! $user_id ) continue;

                $registered = get_userdata( $user_id );
                if ( ! $registered ) continue;

                $reg_date   = strtotime( $registered->user_registered );
                $order_date = $order->get_date_created() ? $order->get_date_created()->getTimestamp() : 0;
                if ( $reg_date && $order_date ) {
                    $diff = max( 0, round( ( $order_date - $reg_date ) / 86400 ) );
                    $days[] = $diff;
                }
            }
        }

        if ( empty( $days ) ) {
            return array( 'avg_days' => '—', 'median_days' => '—', 'same_day_pct' => '—' );
        }

        sort( $days );
        $avg    = round( array_sum( $days ) / count( $days ), 1 );
        $median = $days[ (int) floor( count( $days ) / 2 ) ];
        $same   = round( ( count( array_filter( $days, function( $d ) { return $d === 0; } ) ) / count( $days ) ) * 100 );

        return array( 'avg_days' => $avg, 'median_days' => $median, 'same_day_pct' => $same );
    }

    /**
     * Auto-detect pages with [slw_*] shortcodes for page intelligence.
     */
    private static function get_tracked_pages() {
        global $wpdb;

        $shortcode_pages = array(
            'quiz-results'       => array( 'title' => 'Quiz Results', 'metric_label' => 'Page → Add to Cart %', 'metric_fn' => 'get_quiz_metric' ),
            'wholesale-portal'   => array( 'title' => 'Wholesale Portal', 'metric_label' => 'Login → Order %', 'metric_fn' => null ),
            'wholesale-booth'    => array( 'title' => 'Booth Form', 'metric_label' => 'Start → Complete %', 'metric_fn' => null ),
            'wholesale-activate' => array( 'title' => 'Activation Form', 'metric_label' => 'Visit → Submit %', 'metric_fn' => null ),
            'wholesale-partners' => array( 'title' => 'Wholesale Application', 'metric_label' => 'Visit → Submit %', 'metric_fn' => null ),
        );

        $pages = array();
        foreach ( $shortcode_pages as $slug => $config ) {
            $exists = get_page_by_path( $slug );
            if ( $exists ) {
                $metric_value = '—';
                if ( $config['metric_fn'] && method_exists( __CLASS__, $config['metric_fn'] ) ) {
                    $metric_value = call_user_func( array( __CLASS__, $config['metric_fn'] ) );
                }
                $pages[] = array(
                    'slug'         => $slug,
                    'title'        => $config['title'],
                    'metric_label' => $config['metric_label'],
                    'metric_value' => $metric_value,
                );
            }
        }

        return $pages;
    }

    /**
     * Quiz results page metric — approximate conversion rate.
     */
    private static function get_quiz_metric() {
        global $wpdb;
        $visitors = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta} WHERE meta_key = 'slw_skin_concern'"
        );
        if ( $visitors < 1 ) return '—';

        $purchasers = 0;
        if ( function_exists( 'wc_get_orders' ) ) {
            $orders = wc_get_orders( array(
                'status' => array( 'completed', 'processing' ),
                'limit'  => -1,
                'return' => 'ids',
            ) );
            $purchasers = count( $orders );
        }

        $rate = round( ( $purchasers / $visitors ) * 100, 1 );
        return $rate . '%';
    }
}
