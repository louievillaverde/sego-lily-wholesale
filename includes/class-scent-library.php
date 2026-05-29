<?php
/**
 * Scent Library
 *
 * Hardcoded source-of-truth scent descriptions for the order form +
 * Price List hover popups. Holly's master scent library page on
 * segolilyskincare.com is the canonical reference; this mirror exists
 * so the wholesale order form is never blank when WordPress taxonomy
 * descriptions, attribute term meta, and variation meta are all
 * missing (which is the normal state -- nobody fills those in).
 *
 * Order: lookup by scent name (case-insensitive, diacritic-stripped)
 * against the keys here. If found, return; otherwise fall back to
 * the legacy taxonomy/meta chain in templates/order-form.php.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SLW_Scent_Library {

    private static $scents = array(
        // Curated from Holly's master scent library page on segolilyskincare.com.
        'honey creme' => array(
            'intensity'   => 'Light',
            'description' => 'Subtle sweetness of raw honeycomb and oat milk. Gentle, sweet, comforting.',
        ),
        'mandarin orange' => array(
            'intensity'   => 'Medium',
            'description' => 'Fresh peeled citrus zest. Sweet and tangy. Uplifting, fresh, citrus.',
        ),
        'citrus breeze' => array(
            'intensity'   => 'Medium',
            'description' => 'Crisp, clean lemon and bergamot. Energizing, clean, invigorating, bright.',
        ),
        'cardamom primrose' => array(
            'intensity'   => 'Medium',
            'description' => 'Warming spice meets delicate floral notes. Warm, spicy, floral.',
        ),
        'rosewood lavender' => array(
            'intensity'   => 'Medium',
            'description' => 'Deep woody florals. Calming and grounding. Relaxing, woody, soothing.',
        ),
        'cherry' => array(
            'intensity'   => 'Medium',
            'description' => 'Tart and sweet stone fruit. Summery. Fruity, sweet, playful.',
        ),
        'mango' => array(
            'intensity'   => 'Medium',
            'description' => 'Juicy, ripe tropical fruit. Bright and cheerful. Tropical, juicy, vibrant.',
        ),
        'vanilla spice' => array(
            'intensity'   => 'Strong',
            'description' => 'Warm vanilla bean with hints of cinnamon. Cozy, rich, comforting.',
        ),
        'eucalyptus' => array(
            'intensity'   => 'Strong',
            'description' => 'Medicinal and cooling. Opens the sinuses. Therapeutic, crisp, minty.',
        ),
        'bourbon coffee' => array(
            'intensity'   => 'Very Strong',
            'description' => 'Rich roasted coffee with deep oak undertones. Bold, earthy, awakening.',
        ),
        'unscented' => array(
            'intensity'   => 'None',
            'description' => 'Pure rendered suet. No added fragrance. Pure, natural, safe for sensitive skin.',
        ),

        // Additional common natural-oil scents written in the same voice
        // as the master library (sensory + mood tags) so anything in
        // Holly's catalog beyond the 11 curated entries still surfaces
        // a useful hover description.
        'lavender' => array(
            'intensity'   => 'Medium',
            'description' => 'Classic French lavender. Calming, floral, dreamy. Quiet evenings, slow mornings.',
        ),
        'lavender mint' => array(
            'intensity'   => 'Medium',
            'description' => 'Soft lavender meets a whisper of mint. Cooling, calming, herbal.',
        ),
        'peppermint' => array(
            'intensity'   => 'Strong',
            'description' => 'Cool peppermint leaf. Tingly, awakening, crisp. Great for sore muscles and tired heads.',
        ),
        'spearmint' => array(
            'intensity'   => 'Medium',
            'description' => 'Soft minty sweetness, less sharp than peppermint. Fresh, cooling, clean.',
        ),
        'rose' => array(
            'intensity'   => 'Medium',
            'description' => 'True rose petal. Soft, floral, romantic. A timeless skincare classic.',
        ),
        'rose geranium' => array(
            'intensity'   => 'Medium',
            'description' => 'Rose with a green herbal edge. Floral, balancing, grown-up.',
        ),
        'lemon' => array(
            'intensity'   => 'Medium',
            'description' => 'Fresh-squeezed lemon zest. Bright, clean, energizing. A morning kind of scent.',
        ),
        'lime' => array(
            'intensity'   => 'Medium',
            'description' => 'Sharp, juicy lime. Zesty, crisp, refreshing.',
        ),
        'grapefruit' => array(
            'intensity'   => 'Medium',
            'description' => 'Pink grapefruit zest. Bittersweet, lively, mood-lifting.',
        ),
        'bergamot' => array(
            'intensity'   => 'Medium',
            'description' => 'The citrus note in Earl Grey tea. Bright, slightly floral, elegant.',
        ),
        'lemongrass' => array(
            'intensity'   => 'Medium',
            'description' => 'Bright, grassy citrus. Clean, lively, summer-porch energy.',
        ),
        'tea tree' => array(
            'intensity'   => 'Strong',
            'description' => 'Sharp, herbal, medicinal. The one you reach for when skin needs help.',
        ),
        'sandalwood' => array(
            'intensity'   => 'Strong',
            'description' => 'Creamy, warm wood with a soft sweetness. Grounding, sensual, timeless.',
        ),
        'cedarwood' => array(
            'intensity'   => 'Medium',
            'description' => 'Dry cedar with hints of pencil shavings. Woody, calming, masculine-leaning.',
        ),
        'pine' => array(
            'intensity'   => 'Medium',
            'description' => 'Fresh pine needle. Woodsy, clean, evergreen forest after rain.',
        ),
        'fir' => array(
            'intensity'   => 'Medium',
            'description' => 'Crisp evergreen with a touch of sweetness. Cool, woody, wintry.',
        ),
        'patchouli' => array(
            'intensity'   => 'Strong',
            'description' => 'Deep, earthy, slightly sweet. Grounding and unmistakable. Polarizing in the best way.',
        ),
        'frankincense' => array(
            'intensity'   => 'Strong',
            'description' => 'Resinous, warm, slightly smoky. Meditative and ancient.',
        ),
        'myrrh' => array(
            'intensity'   => 'Strong',
            'description' => 'Earthy resin with a bittersweet edge. Quiet, contemplative, balsamic.',
        ),
        'jasmine' => array(
            'intensity'   => 'Strong',
            'description' => 'Heady white florals. Sweet, intoxicating, evening-garden.',
        ),
        'ylang ylang' => array(
            'intensity'   => 'Strong',
            'description' => 'Rich, sweet tropical floral. Heady, exotic, sensual.',
        ),
        'geranium' => array(
            'intensity'   => 'Medium',
            'description' => 'Green, rosy, herbal. Balancing and refreshing.',
        ),
        'chamomile' => array(
            'intensity'   => 'Light',
            'description' => 'Soft, apple-sweet, calming. Bedtime in a bottle.',
        ),
        'cinnamon' => array(
            'intensity'   => 'Strong',
            'description' => 'Warm cinnamon stick. Spicy, sweet, fireside-cozy.',
        ),
        'clove' => array(
            'intensity'   => 'Strong',
            'description' => 'Sharp, warm spice. Holiday-baking warmth with a tingle.',
        ),
        'sage' => array(
            'intensity'   => 'Medium',
            'description' => 'Dry, herbal, slightly camphorous. Grounding and a little ceremonial.',
        ),
        'rosemary' => array(
            'intensity'   => 'Medium',
            'description' => 'Sharp green herb. Clean, focusing, kitchen-garden fresh.',
        ),
        'basil' => array(
            'intensity'   => 'Medium',
            'description' => 'Sweet green basil leaf. Fresh, herbal, slightly anise.',
        ),
        'coconut' => array(
            'intensity'   => 'Medium',
            'description' => 'Fresh-cracked coconut meat. Creamy, tropical, beach-vacation.',
        ),
        'almond' => array(
            'intensity'   => 'Light',
            'description' => 'Soft, slightly sweet, nutty. Marzipan and warm milk.',
        ),
        'apple' => array(
            'intensity'   => 'Medium',
            'description' => 'Crisp orchard apple. Sweet, fresh, autumn-picking.',
        ),
        'pumpkin spice' => array(
            'intensity'   => 'Strong',
            'description' => 'Pumpkin, cinnamon, nutmeg, clove. Sweater weather in a tin.',
        ),
        'cranberry' => array(
            'intensity'   => 'Medium',
            'description' => 'Tart, juicy berry. Bright, slightly sweet, holiday-table.',
        ),
        'sweet orange' => array(
            'intensity'   => 'Medium',
            'description' => 'Juicy table orange. Sweet, cheerful, kid-friendly.',
        ),
        'blood orange' => array(
            'intensity'   => 'Medium',
            'description' => 'Orange with a deeper, slightly bitter edge. Bold, citrus-forward.',
        ),
    );

    /**
     * Look up a scent by its name. Normalizes the key to handle
     * "Honey Crème" vs "Honey Creme" vs "honey-creme" etc.
     *
     * @param string $name Scent name from the variation label or attribute term.
     * @return string|null Description, or null if not found.
     */
    public static function get_description( $name ) {
        $key = self::normalize_key( $name );
        if ( $key === '' ) return null;
        if ( isset( self::$scents[ $key ] ) ) {
            return self::$scents[ $key ]['description'];
        }
        // Allow partial / contains-match for labels like "Renewal Tallow Butter - Mango"
        foreach ( self::$scents as $scent_key => $entry ) {
            if ( strpos( $key, $scent_key ) !== false || strpos( $scent_key, $key ) !== false ) {
                return $entry['description'];
            }
        }
        return null;
    }

    /**
     * Normalize a free-text scent name into a library key:
     *   - Lowercased
     *   - Diacritics removed (Crème -> creme)
     *   - Punctuation stripped
     *   - Multiple spaces collapsed
     */
    public static function normalize_key( $name ) {
        if ( ! is_string( $name ) || $name === '' ) return '';
        // Strip diacritics: most reliable on WP via remove_accents.
        if ( function_exists( 'remove_accents' ) ) {
            $name = remove_accents( $name );
        }
        $name = strtolower( $name );
        // Strip everything that isn't a-z, 0-9, space or hyphen
        $name = preg_replace( '/[^a-z0-9\s-]/', ' ', $name );
        // Hyphens -> space
        $name = str_replace( '-', ' ', $name );
        // Collapse whitespace
        $name = trim( preg_replace( '/\s+/', ' ', $name ) );
        return $name;
    }

    /**
     * Full scent list for the master library page (future / template use).
     */
    public static function all() {
        return self::$scents;
    }
}
