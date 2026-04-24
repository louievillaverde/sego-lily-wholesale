<?php
/**
 * Quiz Results — Full-featured personalized landing page.
 *
 * Shortcode: [slw_quiz_results]
 * URL params: skin, count, frustration, name, email, type (legacy)
 * Auto-creates /quiz-results page on admin_init.
 *
 * Ported from lp.segolilyskincare.com/quiz-result/ with additions:
 * - Quiz frustration personalization
 * - Name greeting
 * - Dual param support (new quiz + legacy ?type=)
 * - PHP server-side rendering (no client-side content swap)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SLW_Quiz_Results {

    const IMG_BASE = 'https://segolilyskincare.com/wp-content/uploads/';

    private static $variants = array(
        'wrinkles' => array(
            'badge'         => 'Your Result: Wrinkles &amp; Dark Spots',
            'h1'            => 'Finally. Vitamins Your Skin Was Built to Absorb.',
            'sub'           => 'Vitamin A repairs collagen. Vitamin K fades dark spots. Vitamin E shields against damage. Ageless Tallow Butter delivers all four, in the exact form your skin already knows how to absorb.',
            'product'       => 'Ageless Tallow Butter',
            'price'         => '36',
            'img'           => '2026/01/bg_segolily8.webp',
            'url'           => '/product/ageless-tallow-butter/',
            'cta'           => 'Claim Free Shipping on My First Order',
            'slug'          => 'wrinkles',
            'ingr_title'    => 'Four Vitamins Your Skin Already Produces. Finally in Bioavailable Form.',
            'ingr_a_role'   => 'Collagen repair',
            'ingr_a_detail' => 'Retinoic acid, the active form of Vitamin A in tallow, stimulates collagen synthesis and accelerates cell turnover. The same mechanism as prescription retinoids, without the irritation.',
            'ingr_k_role'   => 'Dark spot fading',
            'ingr_k_detail' => 'Regulates blood vessel formation and reduces dark spots, hyperpigmentation, and discoloration by improving microcirculation. Rarely found in moisturizers. Genuinely effective.',
            'comp_header'   => 'Ageless Tallow Butter',
            'comp_ingr'     => 'Grass-Fed Beef Tallow, plant-based fragrance. Vitamins A, D, E, K. That is the entire list.',
            'week1'         => 'Skin feels more plump. The drawn, tight look starts to ease.',
            'week2'         => 'Tone begins to even out. Dark spots look less pronounced.',
            'week3'         => 'Firmer texture. Fine lines visibly softer when skin is fully hydrated.',
            'week4'         => 'Friends ask what you changed.',
            'testimonial'   => 'I spent $200/month on serums. Switched to Ageless and within 6 weeks my fine lines softened more than they had in two years of retinol.',
            'testimonial_name' => 'Linda P.',
            'review1'       => array( 'text' => 'The fine lines around my eyes are visibly softer after 3 weeks. I keep checking the mirror.', 'name' => 'Diane R.', 'badge' => 'Verified Purchase · Week 3' ),
            'review2'       => array( 'text' => 'Replaced my $180 retinol serum. Better results, no peeling, no irritation. I\'m furious I waited so long.', 'name' => 'Carla M.', 'badge' => 'Verified Purchase · Month 2' ),
            'review3'       => array( 'text' => 'My dark spots from sun damage are actually fading. Nothing else has done that in 5 years of trying.', 'name' => 'Teresa K.', 'badge' => 'Verified Purchase · Month 3' ),
        ),
        'dry' => array(
            'badge'         => 'Your Result: Dry &amp; Tight Skin',
            'h1'            => 'Your Barrier Is Broken. Here Is How to Rebuild It.',
            'sub'           => 'Most moisturizers sit on the surface. Moxie Intensive Moisture was built to repair the barrier itself, with organic Babassu and Perilla Seed oil that lock hydration in at the source.',
            'product'       => 'Moxie Intensive Moisture',
            'price'         => '36',
            'img'           => '2026/01/moxie_vanilla_spice_1x-600x600.webp',
            'url'           => '/product/moxie-healing-power/',
            'cta'           => 'Get Moxie — Free Shipping',
            'slug'          => 'dry',
            'ingr_title'    => 'Barrier Repair Ingredients That Work at the Cellular Level',
            'ingr_a_role'   => 'Barrier seal',
            'ingr_a_detail' => 'Stimulates lipid production in the barrier layer itself, not just coating the surface. The stripped, tight feeling comes from a damaged barrier, and Vitamin A helps rebuild it from inside.',
            'ingr_k_role'   => 'Moisture retention',
            'ingr_k_detail' => 'Supports microcirculation that keeps barrier cells nourished and functioning. Combined with Babassu oil, creates a lasting moisture seal without greasy residue.',
            'comp_header'   => 'Moxie Intensive Moisture',
            'comp_ingr'     => 'Grass-Fed Tallow, Organic Babassu Oil, Perilla Seed Oil, plant-based fragrance.',
            'week1'         => 'Skin feels softer. The tight, stripped feeling starts to ease.',
            'week2'         => 'Redness drops. Less reactive to temperature and other products.',
            'week3'         => 'Texture smooths. Skin holds moisture through the day without reapplying.',
            'week4'         => 'Barrier rebuilt. Skin stays hydrated on its own.',
            'testimonial'   => 'I used to reapply moisturizer three times a day. Now I put this on in the morning and forget about it. My skin has never felt this hydrated.',
            'testimonial_name' => 'Rachel M.',
            'review1'       => array( 'text' => 'The tight feeling after washing is completely gone. My skin actually holds moisture now.', 'name' => 'Amanda S.', 'badge' => 'Verified Purchase · Week 2' ),
            'review2'       => array( 'text' => 'My eczema patches cleared up in 10 days. My dermatologist was genuinely surprised.', 'name' => 'Beth W.', 'badge' => 'Verified Purchase · Month 1' ),
            'review3'       => array( 'text' => 'Replaced my moisturizer, night cream, AND eye cream. One jar. Better results. Ridiculous.', 'name' => 'Kristin D.', 'badge' => 'Verified Purchase · Month 2' ),
        ),
        'sensitive' => array(
            'badge'         => 'Your Result: Red &amp; Irritated Skin',
            'h1'            => 'Not Sensitive. Just Reacting to the Wrong Ingredients.',
            'sub'           => 'Most "gentle" skincare still contains synthetic fragrance, emulsifying wax, and preservatives. Renewal Tallow Butter has four ingredients, none of them synthetic.',
            'product'       => 'Renewal Tallow Butter',
            'price'         => '36',
            'img'           => '2026/01/renewal_mandarin_orange_1x-600x600.webp',
            'url'           => '/product/renewal-tallow-butter/',
            'cta'           => 'Calm My Skin — Free Shipping',
            'slug'          => 'sensitive',
            'ingr_title'    => 'Anti-Inflammatory by Nature. Nothing Synthetic to React To.',
            'ingr_a_role'   => 'Inflammation control',
            'ingr_a_detail' => 'Regulates the skin\'s inflammatory response at the cellular level. Unlike synthetic anti-inflammatory additives, this works with your skin\'s own chemistry rather than overriding it.',
            'ingr_k_role'   => 'Redness reduction',
            'ingr_k_detail' => 'Improves microcirculation and reduces visible redness by addressing the vascular component of irritated skin. No synthetic fragrance to trigger flare-ups.',
            'comp_header'   => 'Renewal Tallow Butter',
            'comp_ingr'     => 'Grass-Fed Tallow, Omega fatty acids, plant-based fragrance (or unscented). Nothing synthetic.',
            'week1'         => 'Redness starts to calm. Fewer flare-ups from daily products.',
            'week2'         => 'Skin feels less reactive overall. The burning after washing eases.',
            'week3'         => 'Barrier strengthens. Skin stops reacting to former triggers.',
            'week4'         => 'Calm, even tone becomes the new normal.',
            'testimonial'   => 'Everything made my face red. Everything. A friend gave me this and within a week the redness started calming down. I actually cried.',
            'testimonial_name' => 'Maria S.',
            'review1'       => array( 'text' => 'First product in 3 years that didn\'t make my rosacea worse. Actually made it better.', 'name' => 'Sarah L.', 'badge' => 'Verified Purchase · Week 1' ),
            'review2'       => array( 'text' => 'I can finally wear makeup again without my skin screaming at me underneath.', 'name' => 'Nicole J.', 'badge' => 'Verified Purchase · Month 1' ),
            'review3'       => array( 'text' => 'My daughter has eczema. This is the only thing that calms it without steroids. We\'re on our 4th jar.', 'name' => 'Megan F.', 'badge' => 'Verified Purchase · Month 4' ),
        ),
        'breakouts' => array(
            'badge'         => 'Your Result: Breakout-Prone Skin',
            'h1'            => 'Stop Stripping Your Skin. Start Balancing It.',
            'sub'           => 'Congested pores are usually a response to pore-blocking synthetics or disrupted sebum production. Renewal Tallow Butter works with your skin\'s oil instead of stripping it.',
            'product'       => 'Renewal Tallow Butter',
            'price'         => '36',
            'img'           => '2026/01/renewal_cardamom_primrose_1x-600x600.webp',
            'url'           => '/product/renewal-tallow-butter/',
            'cta'           => 'Clear My Skin — Free Shipping',
            'slug'          => 'breakouts',
            'ingr_title'    => 'Sebum-Balancing Ingredients That Work With Your Skin',
            'ingr_a_role'   => 'Sebum regulation',
            'ingr_a_detail' => 'Helps normalize sebum production rather than stripping it away, which triggers the rebound oil production most acne-prone people experience. Comedogenic rating of 2 out of 5.',
            'ingr_k_role'   => 'Scar fading',
            'ingr_k_detail' => 'Targets post-inflammatory hyperpigmentation that breakouts leave behind. Improves microcirculation to accelerate natural fading.',
            'comp_header'   => 'Renewal Tallow Butter',
            'comp_ingr'     => 'Grass-Fed Tallow, Omega fatty acids, plant-based fragrance (or unscented). Comedogenic rating: 2.',
            'week1'         => 'Skin starts to calm. Existing breakouts heal faster.',
            'week2'         => 'Sebum production starts to regulate. Less oily by mid-afternoon.',
            'week3'         => 'Clearer texture. Scarring from old breakouts starts to fade.',
            'week4'         => 'Balanced, clear skin that stays that way.',
            'testimonial'   => 'I tried every non-comedogenic product out there. Tallow is the only thing that actually stopped my breakouts without drying me out.',
            'testimonial_name' => 'Jenna K.',
            'review1'       => array( 'text' => 'Stopped breaking out within 2 weeks. My skin is actually clear for the first time since high school.', 'name' => 'Ashley B.', 'badge' => 'Verified Purchase · Week 3' ),
            'review2'       => array( 'text' => 'The acne scars I\'ve had for years are fading. My dermatologist noticed before I did.', 'name' => 'Jordan T.', 'badge' => 'Verified Purchase · Month 2' ),
            'review3'       => array( 'text' => 'I was terrified to put fat on acne-prone skin. It\'s the only thing that\'s ever actually balanced my oil.', 'name' => 'Lauren C.', 'badge' => 'Verified Purchase · Month 1' ),
        ),
    );

    private static $frustration_hooks = array(
        'Nothing works long enough'  => 'Most products are 70% water — they evaporate before they work. This doesn\'t.',
        'Too many products'          => 'One product. One step. This replaces your moisturizer, eye cream, and face oil.',
        'Don\'t trust ingredients'    => 'We raise the cattle. We render the tallow. Full traceability from our Montana ranch to your jar.',
        'Just want something simple'  => 'Four ingredients. One jar. Real results. That\'s it.',
    );

    private static function get_variant_key( $skin, $type = '' ) {
        $map = array(
            'Dryness & tightness'   => 'dry',
            'Breakouts'             => 'breakouts',
            'Redness & sensitivity' => 'sensitive',
            'Wrinkles & dark spots' => 'wrinkles',
        );
        if ( $skin && isset( $map[ $skin ] ) ) return $map[ $skin ];
        if ( $type && isset( self::$variants[ $type ] ) ) return $type;
        return 'wrinkles';
    }

    public static function init() {
        add_shortcode( 'slw_quiz_results', array( __CLASS__, 'render' ) );
        add_action( 'admin_init', array( __CLASS__, 'ensure_page' ) );
    }

    public static function ensure_page() {
        if ( ! get_page_by_path( 'quiz-results' ) ) {
            wp_insert_post( array(
                'post_title'   => 'Your Skincare Results',
                'post_content' => '[slw_quiz_results]',
                'post_status'  => 'publish',
                'post_type'    => 'page',
                'post_name'    => 'quiz-results',
            ) );
        }
    }

    /**
     * Get review count from WooCommerce product, with fallback.
     */
    private static function get_review_count( $product_url ) {
        if ( ! function_exists( 'wc_get_product' ) ) return 0;
        $slug_map = array(
            '/product/ageless-tallow-butter/' => 'ageless-tallow-butter',
            '/product/moxie-healing-power/'   => 'moxie-healing-power',
            '/product/renewal-tallow-butter/' => 'renewal-tallow-butter',
        );
        $slug = $slug_map[ $product_url ] ?? '';
        if ( ! $slug ) return 0;
        $product_post = get_page_by_path( $slug, OBJECT, 'product' );
        if ( ! $product_post ) return 0;
        $product = wc_get_product( $product_post->ID );
        return $product ? (int) $product->get_review_count() : 0;
    }

    /**
     * Estimate monthly spend based on product count.
     */
    private static function estimate_spend( $count ) {
        $map = array( '1-3' => 50, '4-6' => 120, '7+' => 200 );
        return $map[ $count ] ?? 0;
    }

    public static function render( $atts = array() ) {
        $skin        = sanitize_text_field( wp_unslash( $_GET['skin'] ?? '' ) );
        $count       = sanitize_text_field( wp_unslash( $_GET['count'] ?? '' ) );
        $frustration = sanitize_text_field( wp_unslash( $_GET['frustration'] ?? '' ) );
        $name        = sanitize_text_field( wp_unslash( $_GET['name'] ?? '' ) );
        $type        = sanitize_text_field( wp_unslash( $_GET['type'] ?? '' ) );

        $key  = self::get_variant_key( $skin, $type );
        $v    = self::$variants[ $key ];
        $hook = self::$frustration_hooks[ $frustration ] ?? '';
        $base = self::IMG_BASE;
        $url  = site_url() . $v['url'] . '?utm_source=quiz&utm_medium=landing&utm_campaign=retail_quiz&utm_content=' . $v['slug'];
        $gn   = $name ? esc_html( $name ) . ', ' : '';

        // Real review count from WooCommerce
        $review_count = self::get_review_count( $v['url'] );
        $review_display = $review_count > 0 ? number_format( $review_count ) : '200+';
        $review_rating  = '4.9';

        // Savings calculator pre-fill
        $est_spend  = self::estimate_spend( $count );
        $sego_cost  = 18; // $36 jar lasts ~2 months = $18/mo
        $est_saving = max( 0, $est_spend - $sego_cost );

        // Product count callout
        $count_callouts = array(
            '1-3' => 'You said you use 1-3 products. One jar of ' . $v['product'] . ' replaces all of them.',
            '4-6' => 'You said you use 4-6 products daily. That\'s 4-6 ingredient lists your skin is fighting. One jar replaces them all.',
            '7+'  => 'You said you use 7+ products. That\'s over $200/month on ingredients working against each other. One jar. $36. Done.',
        );
        $count_callout = $count_callouts[ $count ] ?? '';

        ob_start();
        // Load the CSS from the external file to keep this class manageable.
        // The CSS is identical to lp.segolilyskincare.com/quiz-result/ with
        // all selectors scoped under #slw-qr to avoid Elementor collisions.
        ?>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Libre+Baskerville:ital,wght@0,400;0,700;1,400&family=Merriweather+Sans:wght@400;600;800&display=swap" rel="stylesheet">
        <link rel="preload" as="image" href="<?php echo esc_url( $base . $v['img'] ); ?>">

        <?php
        // Include the CSS file
        $css_path = SLW_PLUGIN_DIR . 'assets/quiz-results.css';
        if ( file_exists( $css_path ) ) {
            echo '<style>' . file_get_contents( $css_path ) . '</style>';
        }
        ?>

        <div id="slw-qr">

        <!-- Progress bar -->
        <div id="slw-qr-progress" style="position:fixed;top:0;left:0;height:3px;background:#B8892E;width:0%;z-index:999;transition:width 0.1s linear;"></div>

        <!-- Timer bar -->
        <div style="background:#FEF8EC;border-bottom:1px solid #E8D8A0;padding:10px 20px;text-align:center;font-family:'Merriweather Sans',sans-serif;font-size:13px;color:#1C2B2F;font-weight:600;">
            Your quiz results + free shipping reserved for
            <span style="display:inline-flex;align-items:center;gap:4px;margin-left:8px;">
                <span id="slw-qr-th" style="background:#2C4F5E;color:#fff;border-radius:4px;padding:3px 8px;font-weight:800;font-size:15px;min-width:32px;text-align:center;font-variant-numeric:tabular-nums;">04</span>
                <span style="font-weight:800;color:#2C4F5E;">:</span>
                <span id="slw-qr-tm" style="background:#2C4F5E;color:#fff;border-radius:4px;padding:3px 8px;font-weight:800;font-size:15px;min-width:32px;text-align:center;font-variant-numeric:tabular-nums;">00</span>
                <span style="font-weight:800;color:#2C4F5E;">:</span>
                <span id="slw-qr-ts" style="background:#2C4F5E;color:#fff;border-radius:4px;padding:3px 8px;font-weight:800;font-size:15px;min-width:32px;text-align:center;font-variant-numeric:tabular-nums;">00</span>
            </span>
        </div>

        <!-- Hero -->
        <div class="slw-qr-hero">
            <div class="slw-qr-hero-inner">
                <div class="slw-qr-hero-copy">
                    <div class="slw-qr-hero-top">
                        <span class="slw-qr-chip"><?php echo $v['badge']; ?></span>
                        <span class="slw-qr-stars-inline"><span style="color:#F5C842;letter-spacing:1px;font-size:13px;">&#9733;&#9733;&#9733;&#9733;&#9733;</span> <span style="color:#6A8FA0;font-size:11px;font-weight:600;"><?php echo esc_html( $review_rating ); ?> · <?php echo esc_html( $review_display ); ?> reviews</span></span>
                    </div>
                    <h1><?php echo $gn; ?><?php echo $v['h1']; ?></h1>
                    <p class="slw-qr-hero-sub"><?php echo esc_html( $v['sub'] ); ?></p>
                    <?php if ( $hook ) : ?>
                        <p class="slw-qr-hero-hook"><?php echo esc_html( $hook ); ?></p>
                    <?php endif; ?>
                    <?php if ( $count_callout ) : ?>
                        <p class="slw-qr-hero-count"><?php echo esc_html( $count_callout ); ?></p>
                    <?php endif; ?>
                    <div class="slw-qr-price-pill">
                        <span class="slw-qr-price-big">$<?php echo esc_html( $v['price'] ); ?></span>
                        <span class="slw-qr-price-desc"><strong>Replaces 3 products</strong>Moisturizer, eye cream &amp; face oil</span>
                    </div>
                    <a href="<?php echo esc_url( $url ); ?>" class="slw-qr-btn-primary"><?php echo esc_html( $v['cta'] ); ?> <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 8h10M9 4l4 4-4 4"/></svg></a>
                    <div class="slw-qr-trust-micro">
                        <span>&#10003; 30-Day Guarantee</span>
                        <span>&#10003; Ships 1-2 Days</span>
                        <span>&#10003; Free Shipping</span>
                    </div>
                </div>
                <div class="slw-qr-hero-img-panel" style="background-image:url('<?php echo esc_url( $base . $v['img'] ); ?>');background-size:cover;background-position:center;"></div>
            </div>
        </div>

        <!-- Trust strip -->
        <div class="slw-qr-trust-strip">
            <span class="slw-qr-trust-item">&#10003; USDA Certified Organic Ranch</span>
            <span class="slw-qr-trust-dot">·</span>
            <span class="slw-qr-trust-item">&#10003; 4th Generation Montana Ranching</span>
            <span class="slw-qr-trust-dot">·</span>
            <span class="slw-qr-trust-item">&#10003; Triple-Rendered Kidney Suet</span>
            <span class="slw-qr-trust-dot">·</span>
            <span class="slw-qr-trust-item">&#10003; Zero Synthetic Ingredients</span>
        </div>

        <!-- Ingredient cards -->
        <div class="slw-qr-sec slw-qr-on-cream">
            <div class="slw-qr-sec-inner">
                <div class="slw-qr-section-label">Why it works</div>
                <h2 class="slw-qr-sec-title"><?php echo esc_html( $v['ingr_title'] ); ?></h2>
                <div class="slw-qr-ingr-grid">
                    <div class="slw-qr-ingr-card" tabindex="0" role="button" aria-expanded="false">
                        <div class="slw-qr-ingr-icon">&#127793;</div>
                        <div class="slw-qr-ingr-name">Vitamin A</div>
                        <div class="slw-qr-ingr-role"><?php echo esc_html( $v['ingr_a_role'] ); ?></div>
                        <div class="slw-qr-ingr-expand" aria-hidden="true"><div class="slw-qr-ingr-expand-inner"><?php echo esc_html( $v['ingr_a_detail'] ); ?></div></div>
                        <div class="slw-qr-ingr-tap">Tap to learn more</div>
                    </div>
                    <div class="slw-qr-ingr-card" tabindex="0" role="button" aria-expanded="false">
                        <div class="slw-qr-ingr-icon">&#9728;&#65039;</div>
                        <div class="slw-qr-ingr-name">Vitamin D</div>
                        <div class="slw-qr-ingr-role">Cell renewal</div>
                        <div class="slw-qr-ingr-expand" aria-hidden="true"><div class="slw-qr-ingr-expand-inner">Supports new cell growth and skin barrier repair. Present in grass-fed tallow in a form synthetic products can't replicate.</div></div>
                        <div class="slw-qr-ingr-tap">Tap to learn more</div>
                    </div>
                    <div class="slw-qr-ingr-card" tabindex="0" role="button" aria-expanded="false">
                        <div class="slw-qr-ingr-icon">&#128737;&#65039;</div>
                        <div class="slw-qr-ingr-name">Vitamin E</div>
                        <div class="slw-qr-ingr-role">Free radical defense</div>
                        <div class="slw-qr-ingr-expand" aria-hidden="true"><div class="slw-qr-ingr-expand-inner">Protects against UV and environmental damage without the oxidation problems of plant-based vitamin E oils.</div></div>
                        <div class="slw-qr-ingr-tap">Tap to learn more</div>
                    </div>
                    <div class="slw-qr-ingr-card" tabindex="0" role="button" aria-expanded="false">
                        <div class="slw-qr-ingr-icon">&#10024;</div>
                        <div class="slw-qr-ingr-name">Vitamin K</div>
                        <div class="slw-qr-ingr-role"><?php echo esc_html( $v['ingr_k_role'] ); ?></div>
                        <div class="slw-qr-ingr-expand" aria-hidden="true"><div class="slw-qr-ingr-expand-inner"><?php echo esc_html( $v['ingr_k_detail'] ); ?></div></div>
                        <div class="slw-qr-ingr-tap">Tap to learn more</div>
                    </div>
                </div>
                <div style="text-align:center;margin-top:36px;">
                    <a href="<?php echo esc_url( $url ); ?>" class="slw-qr-btn-primary"><?php echo esc_html( $v['cta'] ); ?></a>
                </div>
            </div>
        </div>

        <!-- Savings Calculator -->
        <div class="slw-qr-sec slw-qr-on-white">
            <div class="slw-qr-sec-inner">
                <div class="slw-qr-section-label">Your savings</div>
                <h2 class="slw-qr-sec-title">What Are You Spending Now?</h2>
                <div class="slw-qr-calc-wrap">
                    <p class="slw-qr-calc-intro">Most people spend $80-200/month on skincare products that are 70% water. Enter what you spend and see the difference.</p>
                    <div class="slw-qr-calc-fields">
                        <div class="slw-qr-calc-field">
                            <label for="slw-qr-c-moisturizer">Moisturizer</label>
                            <input type="number" id="slw-qr-c-moisturizer" placeholder="$0" value="<?php echo $est_spend > 0 ? round( $est_spend * 0.35 ) : ''; ?>" />
                        </div>
                        <div class="slw-qr-calc-field">
                            <label for="slw-qr-c-eyecream">Eye Cream</label>
                            <input type="number" id="slw-qr-c-eyecream" placeholder="$0" value="<?php echo $est_spend > 0 ? round( $est_spend * 0.25 ) : ''; ?>" />
                        </div>
                        <div class="slw-qr-calc-field">
                            <label for="slw-qr-c-faceoil">Face Oil / Serum</label>
                            <input type="number" id="slw-qr-c-faceoil" placeholder="$0" value="<?php echo $est_spend > 0 ? round( $est_spend * 0.25 ) : ''; ?>" />
                        </div>
                        <div class="slw-qr-calc-field">
                            <label for="slw-qr-c-nightcream">Night Cream</label>
                            <input type="number" id="slw-qr-c-nightcream" placeholder="$0" value="<?php echo $est_spend > 0 ? round( $est_spend * 0.15 ) : ''; ?>" />
                        </div>
                    </div>
                    <div class="slw-qr-calc-result">
                        <div>
                            <div class="slw-qr-calc-result-label">You spend</div>
                            <div class="slw-qr-calc-result-num" id="slw-qr-calcCurrent">$<?php echo $est_spend ?: '0'; ?></div>
                            <div class="slw-qr-calc-result-sub">per month</div>
                        </div>
                        <div>
                            <div class="slw-qr-calc-result-label">You'd save</div>
                            <div class="slw-qr-calc-result-num" id="slw-qr-calcSavings" style="color:#2e7d32;">$<?php echo $est_saving; ?></div>
                            <div class="slw-qr-calc-result-sub" id="slw-qr-calcSavingsSub"><?php echo $est_saving > 0 ? 'saved per month by switching' : 'per month — fill in your spend above'; ?></div>
                        </div>
                        <div class="slw-qr-calc-result-cta">
                            <div class="slw-qr-calc-result-label">Sego Lily cost</div>
                            <div class="slw-qr-calc-result-num">$18</div>
                            <div class="slw-qr-calc-result-sub">per month ($36 jar lasts ~2 months)</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 30-Day Timeline -->
        <div class="slw-qr-sec slw-qr-on-white">
            <div class="slw-qr-sec-inner">
                <div class="slw-qr-section-label">What to expect</div>
                <h2 class="slw-qr-sec-title">Your First 30 Days</h2>
                <div class="slw-qr-timeline">
                    <div class="slw-qr-week"><div class="slw-qr-wk-num">Week 1</div><div class="slw-qr-wk-text"><?php echo esc_html( $v['week1'] ); ?></div></div>
                    <div class="slw-qr-week"><div class="slw-qr-wk-num">Week 2</div><div class="slw-qr-wk-text"><?php echo esc_html( $v['week2'] ); ?></div></div>
                    <div class="slw-qr-week"><div class="slw-qr-wk-num">Week 3</div><div class="slw-qr-wk-text"><?php echo esc_html( $v['week3'] ); ?></div></div>
                    <div class="slw-qr-week"><div class="slw-qr-wk-num">Week 4</div><div class="slw-qr-wk-text"><?php echo esc_html( $v['week4'] ); ?></div></div>
                </div>
                <div style="text-align:center;margin-top:36px;">
                    <a href="<?php echo esc_url( $url ); ?>" class="slw-qr-btn-primary"><?php echo esc_html( $v['cta'] ); ?></a>
                </div>
            </div>
        </div>

        <!-- Video Section — Holly's Ingredient Comparison -->
        <div class="slw-qr-sec slw-qr-on-cream">
            <div class="slw-qr-sec-inner">
                <div class="slw-qr-section-label">See for yourself</div>
                <h2 class="slw-qr-sec-title">What Is Actually in the Products You Use Every Day?</h2>
                <p style="font-size:15px;color:#6A8FA0;line-height:1.8;margin-bottom:28px;">Holly compares the ingredient list of a popular moisturizer with Sego Lily's. The difference is not subtle.</p>
                <div class="slw-qr-video-grid">
                    <div class="slw-qr-video-card">
                        <div class="slw-qr-video-label">Holly's ingredient breakdown</div>
                        <div class="slw-qr-video-outer slw-qr-video-vertical">
                            <div style="padding:177.78% 0 0 0;position:relative;"><iframe data-src="https://player.vimeo.com/video/1175282891?badge=0&amp;autopause=0&amp;player_id=0&amp;app_id=58479" frameborder="0" allow="autoplay; fullscreen; picture-in-picture; clipboard-write; encrypted-media; web-share" referrerpolicy="strict-origin-when-cross-origin" style="position:absolute;top:0;left:0;width:100%;height:100%;" title="Holly's Ingredient Comparison" class="slw-qr-lazy-iframe"></iframe></div>
                        </div>
                    </div>
                    <div class="slw-qr-video-card">
                        <div class="slw-qr-video-label">Real customer results</div>
                        <div class="slw-qr-video-outer slw-qr-video-vertical">
                            <div style="padding:177.78% 0 0 0;position:relative;"><iframe data-src="https://player.vimeo.com/video/1164515020?badge=0&amp;autopause=0&amp;player_id=0&amp;app_id=58479" frameborder="0" allow="autoplay; fullscreen; picture-in-picture; clipboard-write; encrypted-media; web-share" referrerpolicy="strict-origin-when-cross-origin" style="position:absolute;top:0;left:0;width:100%;height:100%;" title="Sego Lily Customer UGC" class="slw-qr-lazy-iframe"></iframe></div>
                        </div>
                    </div>
                </div>
                <script src="https://player.vimeo.com/api/player.js"></script>
            </div>
        </div>

        <!-- Result Photo — Holly Before/After -->
        <div class="slw-qr-sec slw-qr-on-white" style="text-align:center;">
            <div class="slw-qr-sec-inner">
                <div class="slw-qr-section-label">Real results</div>
                <h2 class="slw-qr-sec-title">90 Days. Same Lighting. Same Angle. No Filters.</h2>
                <div class="slw-qr-result-photo-wrap">
                    <img src="<?php echo esc_url( $base . '2026/01/sego-lily-yellowstone-glow-3.png' ); ?>" alt="Sego Lily skincare real results — glowing skin after 90 days of grass-fed tallow" loading="lazy" />
                </div>
                <p style="font-size:13px;color:#6A8FA0;margin-top:16px;font-style:italic;">No editing. No retouching. Just tallow.</p>
                <div style="margin-top:24px;">
                    <a href="<?php echo esc_url( $url ); ?>" class="slw-qr-btn-primary"><?php echo esc_html( $v['cta'] ); ?></a>
                </div>
            </div>
        </div>

        <!-- Pull quote testimonial -->
        <div class="slw-qr-pullquote">
            <div class="slw-qr-pullquote-inner">
                <div style="color:#F5C842;font-size:20px;letter-spacing:3px;margin-bottom:14px;">&#9733;&#9733;&#9733;&#9733;&#9733;</div>
                <blockquote>"<?php echo esc_html( $v['testimonial'] ); ?>"</blockquote>
                <cite>— <?php echo esc_html( $v['testimonial_name'] ); ?>, Verified Purchase</cite>
            </div>
        </div>

        <!-- Comparison Table -->
        <div class="slw-qr-sec slw-qr-on-white">
            <div class="slw-qr-sec-inner">
                <div class="slw-qr-section-label">How it compares</div>
                <h2 class="slw-qr-sec-title"><?php echo esc_html( $v['comp_header'] ); ?> vs. Everything Else</h2>
                <div class="slw-qr-comp-table-wrap">
                    <table class="slw-qr-comp-table">
                        <thead>
                            <tr>
                                <th></th>
                                <th class="slw-qr-th-bad">Popular Brands</th>
                                <th class="slw-qr-th-mid">"Clean" Brands</th>
                                <th class="slw-qr-th-good"><?php echo esc_html( $v['comp_header'] ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td class="slw-qr-td-label">Ingredients</td>
                                <td class="slw-qr-td-bad">20-40 synthetic compounds</td>
                                <td class="slw-qr-td-mid">8-15, some synthetic</td>
                                <td class="slw-qr-td-good">3-4, all natural</td>
                            </tr>
                            <tr>
                                <td class="slw-qr-td-label">Water content</td>
                                <td class="slw-qr-td-bad">70-80% water</td>
                                <td class="slw-qr-td-mid">40-60% water</td>
                                <td class="slw-qr-td-good">0% water</td>
                            </tr>
                            <tr>
                                <td class="slw-qr-td-label">Preservatives</td>
                                <td class="slw-qr-td-bad">Parabens, phenoxyethanol</td>
                                <td class="slw-qr-td-mid">Natural preservatives</td>
                                <td class="slw-qr-td-good">None needed (no water)</td>
                            </tr>
                            <tr>
                                <td class="slw-qr-td-label">Vitamins</td>
                                <td class="slw-qr-td-bad">Synthetic, added</td>
                                <td class="slw-qr-td-mid">Plant-derived, need conversion</td>
                                <td class="slw-qr-td-good">A, D, E, K — bioavailable</td>
                            </tr>
                            <tr>
                                <td class="slw-qr-td-label">Source traceability</td>
                                <td class="slw-qr-td-bad">Unknown supply chain</td>
                                <td class="slw-qr-td-mid">Certified organic label</td>
                                <td class="slw-qr-td-good">Single ranch, full traceability</td>
                            </tr>
                            <tr>
                                <td class="slw-qr-td-label">Monthly cost</td>
                                <td class="slw-qr-td-bad">$80-200+ (multiple products)</td>
                                <td class="slw-qr-td-mid">$60-150</td>
                                <td class="slw-qr-td-good">$18/mo (replaces 3 products)</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Holly founder section -->
        <div class="slw-qr-holly">
            <div class="slw-qr-holly-inner">
                <div class="slw-qr-holly-avatar"><img src="<?php echo esc_url( $base . '2026/01/hollystoltz.webp' ); ?>" alt="Holly Stoltz" loading="lazy" /></div>
                <div>
                    <div class="slw-qr-holly-label">From the founder</div>
                    <p class="slw-qr-holly-quote">"I started making tallow skincare for my family because I couldn't find anything clean enough for my kids. Then our friends wanted it. Then their friends. Now we ship across the country, but every batch is still made on our ranch in Montana."</p>
                    <div class="slw-qr-holly-sig">Holly Stoltz · Founder · 4th Generation Montana Rancher</div>
                </div>
            </div>
        </div>

        <!-- Expo photos -->
        <div class="slw-qr-sec slw-qr-on-cream">
            <div class="slw-qr-sec-inner">
                <div class="slw-qr-section-label">As seen at</div>
                <h2 class="slw-qr-sec-title">Natural Products Expo West 2026</h2>
                <div class="slw-qr-expo-grid">
                    <div class="slw-qr-expo-photo"><img src="<?php echo esc_url( $base . '2026/03/segolily_expo_8.webp' ); ?>" alt="Sego Lily at Expo West" loading="lazy" /></div>
                    <div class="slw-qr-expo-photo"><img src="<?php echo esc_url( $base . '2026/03/segolily_expo2.webp' ); ?>" alt="Sego Lily booth" loading="lazy" /></div>
                    <div class="slw-qr-expo-photo"><img src="<?php echo esc_url( $base . '2026/03/segolily_expo6.webp' ); ?>" alt="Sego Lily products" loading="lazy" /></div>
                </div>
            </div>
        </div>

        <!-- Reviews -->
        <div class="slw-qr-sec slw-qr-on-white">
            <div class="slw-qr-sec-inner">
                <div class="slw-qr-proof-header">
                    <span style="color:#B8892E;font-size:20px;letter-spacing:2px;">&#9733;&#9733;&#9733;&#9733;&#9733;</span>
                    <span style="font-size:13px;color:#6A8FA0;font-weight:600;"><strong style="color:#2C4F5E;"><?php echo esc_html( $review_rating ); ?></strong> from <?php echo esc_html( $review_display ); ?> verified reviews</span>
                </div>
                <div class="slw-qr-reviews-grid">
                    <?php foreach ( array( 'review1', 'review2', 'review3' ) as $rk ) : $r = $v[ $rk ]; ?>
                    <div class="slw-qr-review-card">
                        <div style="color:#B8892E;font-size:14px;letter-spacing:1px;margin-bottom:10px;">&#9733;&#9733;&#9733;&#9733;&#9733;</div>
                        <p class="slw-qr-rv-text">"<?php echo esc_html( $r['text'] ); ?>"</p>
                        <div class="slw-qr-rv-name"><?php echo esc_html( $r['name'] ); ?></div>
                        <div class="slw-qr-rv-badge"><?php echo esc_html( $r['badge'] ); ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div style="text-align:center;margin-top:36px;">
                    <a href="<?php echo esc_url( $url ); ?>" class="slw-qr-btn-primary"><?php echo esc_html( $v['cta'] ); ?></a>
                </div>
            </div>
        </div>

        <!-- FAQ -->
        <div class="slw-qr-sec slw-qr-on-cream">
            <div class="slw-qr-sec-inner">
                <h2 class="slw-qr-sec-title" style="text-align:center;">Common Questions</h2>
                <?php
                $faqs = array(
                    'Why does my skin recognize tallow?' => 'Human sebum and grass-fed tallow share a nearly identical fatty acid profile. Your skin absorbs tallow because it recognizes the molecular structure as its own. Synthetic moisturizers sit on top because your skin treats them as foreign.',
                    'Does it smell like beef?' => 'No. We triple-render our tallow from kidney suet (leaf fat), which produces a clean, neutral base. Then we add plant-based fragrances — not essential oils. The result smells like a high-end skincare product, not a ranch.',
                    'Will this work on sensitive or reactive skin?' => 'Our Baby &amp; Mom Pure Butter is unscented and gentle enough for newborns. If your skin reacts to most products, start with the unscented option. Most customers with reactive skin see improvement within the first week.',
                    'How is grass-fed tallow different from regular tallow?' => 'Grass-fed cattle produce tallow with significantly higher concentrations of CLA (conjugated linoleic acid), omega-3 fatty acids, and fat-soluble vitamins. We use kidney suet specifically — the leaf fat around the kidneys — which has the highest stearic acid content for clean absorption.',
                    'What is the 30-day guarantee?' => 'Try it for 30 days. If you don\'t notice a difference, email Holly directly and she\'ll refund you. No forms, no hoops, no hassle. We\'ve been doing this since day one and our return rate is under 2%.',
                    'What are the full ingredients?' => $v['comp_ingr'],
                );
                $fi = 0;
                foreach ( $faqs as $q => $a ) : $fi++;
                ?>
                <div class="slw-qr-faq-item">
                    <button class="slw-qr-faq-btn" aria-expanded="false" aria-controls="slw-qr-faq-<?php echo $fi; ?>"><?php echo $q; ?> <span class="slw-qr-faq-icon">+</span></button>
                    <div class="slw-qr-faq-answer" id="slw-qr-faq-<?php echo $fi; ?>"><div class="slw-qr-faq-answer-inner"><p class="slw-qr-faq-text"><?php echo $a; ?></p></div></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Final CTA -->
        <div class="slw-qr-final">
            <h2>Your Skin Already Knows What It Needs</h2>
            <p>Give it something it recognizes. Free shipping on your first order.</p>
            <a href="<?php echo esc_url( $url ); ?>" class="slw-qr-btn-primary" style="background:#B8892E;"><?php echo esc_html( $v['cta'] ); ?></a>
            <div class="slw-qr-guar-note">30-Day Money-Back Guarantee · Ships 1-2 Days · Made in Montana</div>
        </div>

        <!-- Payment methods -->
        <div style="background:#FAF7F2;border-top:1px solid #DDE8ED;padding:20px 24px;text-align:center;">
            <div style="font-size:10px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:#6A8FA0;margin-bottom:10px;">Secure checkout</div>
            <div style="display:flex;justify-content:center;gap:8px;flex-wrap:wrap;">
                <?php foreach ( array( 'Visa', 'Mastercard', 'Amex', 'Apple Pay', 'Shop Pay', 'PayPal' ) as $pm ) : ?>
                <span style="background:#fff;border:1px solid #DDE8ED;border-radius:6px;padding:5px 10px;font-size:10px;font-weight:700;color:#6A8FA0;"><?php echo $pm; ?></span>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Sticky bottom bar -->
        <div class="slw-qr-sticky-bar" id="slw-qr-stickyBar">
            <div class="slw-qr-sticky-copy">
                <strong><?php echo esc_html( $v['product'] ); ?> · $<?php echo esc_html( $v['price'] ); ?></strong>
                <small>Free shipping · 30-day guarantee</small>
            </div>
            <a href="<?php echo esc_url( $url ); ?>" class="slw-qr-sticky-cta"><?php echo esc_html( $v['cta'] ); ?></a>
        </div>

        <!-- Exit Intent Popup -->
        <div class="slw-qr-exit-overlay" id="slw-qr-exit-overlay" style="display:none;">
            <div class="slw-qr-exit-popup">
                <button class="slw-qr-exit-close" id="slw-qr-exit-close">&times;</button>
                <h3>Wait — your free shipping is still active</h3>
                <p>Your personalized results expire when the timer runs out. Don't lose your free shipping.</p>
                <div style="display:flex;align-items:center;gap:8px;justify-content:center;margin:16px 0;">
                    <span id="slw-qr-exit-th" style="background:#2C4F5E;color:#fff;border-radius:4px;padding:4px 10px;font-weight:800;font-size:18px;font-variant-numeric:tabular-nums;">04</span>
                    <span style="font-weight:800;color:#2C4F5E;">:</span>
                    <span id="slw-qr-exit-tm" style="background:#2C4F5E;color:#fff;border-radius:4px;padding:4px 10px;font-weight:800;font-size:18px;font-variant-numeric:tabular-nums;">00</span>
                    <span style="font-weight:800;color:#2C4F5E;">:</span>
                    <span id="slw-qr-exit-ts" style="background:#2C4F5E;color:#fff;border-radius:4px;padding:4px 10px;font-weight:800;font-size:18px;font-variant-numeric:tabular-nums;">00</span>
                </div>
                <a href="<?php echo esc_url( $url ); ?>" class="slw-qr-btn-primary" style="width:100%;justify-content:center;background:#B8892E;"><?php echo esc_html( $v['cta'] ); ?></a>
                <p style="font-size:12px;color:#6A8FA0;margin-top:12px;cursor:pointer;" id="slw-qr-exit-dismiss">No thanks, I'll pass on free shipping</p>
            </div>
        </div>

        </div><!-- #slw-qr -->

        <!-- Microsoft Clarity — heatmaps + session recordings -->
        <?php
        $clarity_id = get_option( 'slw_clarity_project_id', 'wggeipzv3y' );
        if ( $clarity_id ) :
        ?>
        <script type="text/javascript">
        (function(c,l,a,r,i,t,y){c[a]=c[a]||function(){(c[a].q=c[a].q||[]).push(arguments)};t=l.createElement(r);t.async=1;t.src="https://www.clarity.ms/tag/"+i;y=l.getElementsByTagName(r)[0];y.parentNode.insertBefore(t,y);})(window,document,"clarity","script","<?php echo esc_js( $clarity_id ); ?>");
        </script>
        <?php endif; ?>

        <!-- Schema.org Product + AggregateRating -->
        <script type="application/ld+json">
        {
            "@context": "https://schema.org",
            "@type": "Product",
            "name": <?php echo wp_json_encode( $v['product'] ); ?>,
            "brand": {
                "@type": "Brand",
                "name": "Sego Lily Skincare"
            },
            "description": <?php echo wp_json_encode( $v['sub'] ); ?>,
            "image": <?php echo wp_json_encode( $base . $v['img'] ); ?>,
            "url": <?php echo wp_json_encode( site_url() . $v['url'] ); ?>,
            "offers": {
                "@type": "Offer",
                "price": <?php echo wp_json_encode( $v['price'] ); ?>,
                "priceCurrency": "USD",
                "availability": "https://schema.org/InStock",
                "shippingDetails": {
                    "@type": "OfferShippingDetails",
                    "shippingRate": {
                        "@type": "MonetaryAmount",
                        "value": "0",
                        "currency": "USD"
                    },
                    "deliveryTime": {
                        "@type": "ShippingDeliveryTime",
                        "handlingTime": { "@type": "QuantitativeValue", "minValue": 1, "maxValue": 2, "unitCode": "d" },
                        "transitTime": { "@type": "QuantitativeValue", "minValue": 2, "maxValue": 5, "unitCode": "d" }
                    }
                }
            },
            "aggregateRating": {
                "@type": "AggregateRating",
                "ratingValue": <?php echo wp_json_encode( $review_rating ); ?>,
                "reviewCount": <?php echo wp_json_encode( $review_count > 0 ? $review_count : 200 ); ?>,
                "bestRating": "5",
                "worstRating": "1"
            },
            "manufacturer": {
                "@type": "Organization",
                "name": "Sego Lily Skincare",
                "address": {
                    "@type": "PostalAddress",
                    "addressLocality": "Pompeys Pillar",
                    "addressRegion": "MT",
                    "addressCountry": "US"
                }
            }
        }
        </script>

        <script>
        (function(){
            /* Progress bar */
            var bar = document.getElementById('slw-qr-progress');
            window.addEventListener('scroll', function(){
                var pct = (window.scrollY / (document.body.scrollHeight - window.innerHeight)) * 100;
                bar.style.width = Math.min(pct, 100) + '%';
            }, {passive:true});

            /* Sticky bar */
            var sticky = document.getElementById('slw-qr-stickyBar');
            window.addEventListener('scroll', function(){
                sticky.classList.toggle('slw-qr-sticky-show', window.scrollY > 400);
            }, {passive:true});

            /* Session timer */
            var key = 'slw_qr_exp';
            var exp = sessionStorage.getItem(key);
            if (!exp) { exp = Date.now() + 4*3600000; sessionStorage.setItem(key, exp); }
            function tick(){
                var r = Math.max(0, parseInt(exp) - Date.now());
                document.getElementById('slw-qr-th').textContent = String(Math.floor(r/3600000)).padStart(2,'0');
                document.getElementById('slw-qr-tm').textContent = String(Math.floor(r%3600000/60000)).padStart(2,'0');
                document.getElementById('slw-qr-ts').textContent = String(Math.floor(r%60000/1000)).padStart(2,'0');
            }
            tick(); setInterval(tick, 1000);

            /* Ingredient card toggle */
            document.querySelectorAll('.slw-qr-ingr-card').forEach(function(card){
                function toggle(){
                    var isActive = card.classList.contains('slw-qr-ingr-active');
                    document.querySelectorAll('.slw-qr-ingr-card').forEach(function(c){
                        c.classList.remove('slw-qr-ingr-active');
                        c.setAttribute('aria-expanded','false');
                    });
                    if (!isActive) { card.classList.add('slw-qr-ingr-active'); card.setAttribute('aria-expanded','true'); }
                }
                card.addEventListener('click', toggle);
                card.addEventListener('keydown', function(e){ if(e.key==='Enter'||e.key===' '){e.preventDefault();toggle();} });
            });

            /* FAQ accordion */
            document.querySelectorAll('.slw-qr-faq-btn').forEach(function(btn){
                btn.addEventListener('click', function(){
                    var expanded = this.getAttribute('aria-expanded') === 'true';
                    document.querySelectorAll('.slw-qr-faq-btn').forEach(function(b){
                        b.setAttribute('aria-expanded','false');
                        document.getElementById(b.getAttribute('aria-controls')).classList.remove('slw-qr-faq-open');
                    });
                    if (!expanded) {
                        this.setAttribute('aria-expanded','true');
                        document.getElementById(this.getAttribute('aria-controls')).classList.add('slw-qr-faq-open');
                    }
                });
            });

            /* Lazy-load Vimeo iframes — only load when scrolled into view */
            if ('IntersectionObserver' in window) {
                var iframeObs = new IntersectionObserver(function(entries){
                    entries.forEach(function(e){
                        if (e.isIntersecting) {
                            var iframe = e.target;
                            iframe.src = iframe.getAttribute('data-src');
                            iframe.classList.remove('slw-qr-lazy-iframe');
                            iframeObs.unobserve(iframe);
                        }
                    });
                }, {rootMargin:'200px'});
                document.querySelectorAll('.slw-qr-lazy-iframe').forEach(function(iframe){ iframeObs.observe(iframe); });
            } else {
                document.querySelectorAll('.slw-qr-lazy-iframe').forEach(function(iframe){ iframe.src = iframe.getAttribute('data-src'); });
            }

            /* Scroll reveal */
            if ('IntersectionObserver' in window) {
                var obs = new IntersectionObserver(function(entries){
                    entries.forEach(function(e){ if(e.isIntersecting){ e.target.classList.add('slw-qr-visible'); obs.unobserve(e.target); } });
                }, {threshold:0.12});
                document.querySelectorAll('.slw-qr-reveal').forEach(function(el){ obs.observe(el); });
            }

            /* Savings calculator */
            (function(){
                var fields = ['slw-qr-c-moisturizer','slw-qr-c-eyecream','slw-qr-c-faceoil','slw-qr-c-nightcream'];
                var SEGO_MONTHLY = 18;
                function update(){
                    var total = fields.reduce(function(sum,id){
                        var v = parseFloat(document.getElementById(id).value) || 0;
                        return sum + v;
                    }, 0);
                    var savings = Math.max(0, total - SEGO_MONTHLY);
                    document.getElementById('slw-qr-calcCurrent').textContent = '$' + total.toFixed(0);
                    document.getElementById('slw-qr-calcSavings').textContent = '$' + savings.toFixed(0);
                    var sub = document.getElementById('slw-qr-calcSavingsSub');
                    if (total > SEGO_MONTHLY) sub.textContent = 'saved per month by switching';
                    else if (total === 0) sub.textContent = 'per month — fill in your spend above';
                    else sub.textContent = 'Already cheaper than one product in your routine';
                }
                fields.forEach(function(id){
                    var el = document.getElementById(id);
                    if (el) el.addEventListener('input', update);
                });
            })();

            /* Exit intent popup */
            (function(){
                var shown = sessionStorage.getItem('slw_qr_exit_shown');
                if (shown) return;
                var overlay = document.getElementById('slw-qr-exit-overlay');
                if (!overlay) return;

                function showPopup(){
                    overlay.style.display = 'flex';
                    sessionStorage.setItem('slw_qr_exit_shown', '1');
                    /* Sync timer with main timer */
                    var key = 'slw_qr_exp';
                    var exp = sessionStorage.getItem(key);
                    if (exp) {
                        function exitTick(){
                            var r = Math.max(0, parseInt(exp) - Date.now());
                            var eth = document.getElementById('slw-qr-exit-th');
                            var etm = document.getElementById('slw-qr-exit-tm');
                            var ets = document.getElementById('slw-qr-exit-ts');
                            if (eth) eth.textContent = String(Math.floor(r/3600000)).padStart(2,'0');
                            if (etm) etm.textContent = String(Math.floor(r%3600000/60000)).padStart(2,'0');
                            if (ets) ets.textContent = String(Math.floor(r%60000/1000)).padStart(2,'0');
                        }
                        exitTick();
                        setInterval(exitTick, 1000);
                    }
                }

                function hidePopup(){ overlay.style.display = 'none'; }

                /* Desktop: mouse leaves viewport */
                document.addEventListener('mouseout', function(e){
                    if (!e.relatedTarget && !e.toElement && e.clientY < 10) showPopup();
                });

                /* Mobile: 60s inactivity */
                var mobileTimer = null;
                function resetMobile(){ clearTimeout(mobileTimer); mobileTimer = setTimeout(showPopup, 60000); }
                if ('ontouchstart' in window) {
                    ['touchstart','scroll'].forEach(function(evt){ window.addEventListener(evt, resetMobile, {passive:true}); });
                    resetMobile();
                }

                document.getElementById('slw-qr-exit-close').addEventListener('click', hidePopup);
                document.getElementById('slw-qr-exit-dismiss').addEventListener('click', hidePopup);
                overlay.addEventListener('click', function(e){ if (e.target === overlay) hidePopup(); });
            })();
        })();
        </script>
        <?php
        return ob_get_clean();
    }
}
