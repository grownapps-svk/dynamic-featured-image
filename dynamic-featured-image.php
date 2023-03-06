<?php
/**
 * Plugin Name: Dynamic Featured Image
 * Plugin URI: http://wordpress.org/plugins/dynamic-featured-image/
 * Description: Dynamically adds featured images or post thumbnails to your posts, pages and custom post types.
 * Version: 3.7.1
 * Author: Ankit Pokhrel (Original), Maros Dovec (Cleanup & Feature)
 * Author URI: https://grownapps.io
 * License: GPL2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: dynamic-featured-image
 * Domain Path: /languages
 * GitHub Plugin URI: https://github.com/qvera/Dynamic-Featured-Image
 *
 * @package dynamic-featured-image
 */

// Avoid direct calls to this file.
if (!defined('ABSPATH')) {
    header('Status: 403 Forbidden');
    header('HTTP/1.1 403 Forbidden');
    exit();
}

/**
 * Grownapps Updated Dynamic Featured Image plugin main class.
 *
 * @author Ankit Pokhrel <info@ankitpokhrel.com>
 * @version 3.7.0
 */
class GADynamicFeaturedImage
{
    /**
     * Current version of the plugin.
     *
     * @since 3.0.0
     */
    const VERSION = '3.7.1';

    /**
     * Text domain.
     *
     * @since 3.6.0
     */
    const TEXT_DOMAIN = 'dynamic-featured-image';

    /**
     * Image upload directory
     *
     * @var string[] $uploadDir
     */
    private array $uploadDir;

    /**
     * Image upload URL.
     */
    private string $uploadUrl;

    /**
     * Database object.
     */
    private wpdb $db;

    /**
     * Title for dfi metabox.
     *
     * @var string[] $metaboxTitle
     */
    protected array $metaboxTitle;

    /**
     * Users post type filter for dfi metabox.
     */
    protected array $userFilter;

    /**
     * Constructor. Hooks all interactions to initialize the class.
     */
    public function __construct(): void
    {
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_scripts' ] );
        add_action( 'add_meta_boxes', [ $this, 'initialize_featured_box' ] );
        add_action( 'save_post', [ $this, 'save_meta' ] );
        add_action( 'plugins_loaded', [ $this, 'load_plugin_textdomain' ] );

        // handle ajax request.
        add_action( 'wp_ajax_dfiMetaBox_callback', [ $this, 'ajax_callback' ] );

        // media uploader custom fields.
        add_filter( 'attachment_fields_to_edit', [ $this, 'media_attachment_custom_fields' ], 10, 2 );
        add_filter( 'attachment_fields_to_save', [ $this, 'media_attachment_custom_fields_save' ], 10, 2 );

        $this->uploadDir = wp_upload_dir();
        $this->uploadUrl = preg_replace( '#^https?://#', '', $this->uploadDir['baseurl'] );

        // add protocol to the upload url.
        $this->uploadUrl = $this->get_protocol() . $this->uploadUrl;

        // post type filter added by user.
        $this->userFilter = [];

        global $wpdb;
        $this->db = $wpdb;
    }

    /**
     * Return site protocol.
     */
    private function get_protocol(): string
    {
        return is_ssl() ? 'https://' : 'http://';
    }

    /**
     * Add required admin scripts.
     */
    public function enqueue_admin_scripts(): void
    {
        // enqueue styles.
        wp_enqueue_style( 'style-dfi', plugins_url( '/css/style-dfi.css', __FILE__ ), [], self::VERSION );

        // register script.
        wp_register_script( 'scripts-dfi', plugins_url( '/js/script-dfi.js', __FILE__ ), ['jquery'], self::VERSION );

        // localize the script with required data.
        wp_localize_script(
            'scripts-dfi',
            'DFI_SPECIFIC',
            [
                'uploadUrl'               => $this->uploadUrl,
                'metaboxTitle'            => __( $this->metaboxTitle, self::TEXT_DOMAIN ),
                'mediaSelector_title'      => __( 'Dynamic Featured Image - Media Selector', self::TEXT_DOMAIN ),
                'mediaSelector_buttonText' => __( 'Set Image', self::TEXT_DOMAIN ),
                'ajax_nonce'               => wp_create_nonce( plugin_basename( __FILE__ ) ),
            ]
        );

        // enqueue scripts.
        wp_enqueue_script( 'scripts-dfi' );
    }

    /**
     * Add featured meta boxes dynamically.
     *
     * @global WP_Post $post
     */
    public function initialize_featured_box(): void
    {
        global $post;

        // make metabox title dynamic.
        $this->metaboxTitle = apply_filters( 'dfi_set_metaboxTitle', [ __( 'Featured Image', self::TEXT_DOMAIN ) ] );

        $featured_data  = get_post_meta( $post->ID, 'dfiFeatured', true );
        $total_featured = is_array( $featured_data ) ? count( $featured_data ) : 0;

        $total_featured = apply_filters( 'dfi_total_featured', $total_featured );
        
        $default_filter    = [ 'attachment', 'revision', 'nav_menu_item' ];
        $this->userFilter = apply_filters( 'dfi_post_type_userFilter', $this->userFilter );

        $post_types = array_diff( get_post_types(), array_merge( $default_filter, $this->userFilter ) );
        $post_types = apply_filters( 'dfi_post_types', $post_types );

        for ($i = 0; $i <= $total_featured; $i++) {
            $this->dfi_add_meta_box($post_types, $featured_data[$i] ?? null, $i);
        }
    }

    /**
     * Adds meta boxes.
     *
     * @param  array  $post_types Post types to show featured image box.
     * @param  object $featured Callback arguments.
     * @param  int    $i Index of the featured image.
     *
     * @return void
     */
    private function dfi_add_meta_box( array $post_types, ?object $featured = null, ?int $i = null ): void
    {
        if ( ! is_null( $i ) ) {
            foreach ( $post_types as $type ) {
                add_meta_box(
                    'dfiFeaturedMetaBox-' . $i,
                    __( $this->metaboxTitle[$i] ?? $i, self::TEXT_DOMAIN ),
                    [ $this, 'featured_meta_box' ],
                    $type,
                    apply_filters( 'dfi_metabox_context', 'side' ),
                    apply_filters( 'dfi_metabox_priority', 'low' ),
                    [ $featured, $i + 1 ]
                );

                add_filter( "postbox_classes_{$type}_dfiFeaturedMetaBox-" . $i, [ $this, 'add_metabox_classes' ] );
            }
        } else {
            foreach ( $post_types as $type ) {
                add_meta_box(
                    'dfiFeaturedMetaBox',
                    __( $this->metaboxTitle[$i] ?? $i, self::TEXT_DOMAIN ),
                    [ $this, 'featured_meta_box' ],
                    $type,
                    apply_filters( 'dfi_metabox_context', 'side' ),
                    apply_filters( 'dfi_metabox_priority', 'low' ),
                    [ null, null ]
                );

                add_filter( "postbox_classes_{$type}_dfiFeaturedMetaBox", [ $this, 'add_metabox_classes' ] );
            }
        }
    }

    /**
     * Separate thumb and full image url from given URL string.
     *
     * @since  3.3.1
     *
     * @param  string $url_string Url string.
     * @param  string $state Thumb or full.
     *
     * @return string|null
     */
    private function separate( $url_string, $state = 'thumb' ) {
        $image_piece = explode( ',', $url_string );

        if ( 'thumb' === $state ) {
            return isset( $image_piece[0] ) ? $image_piece[0] : null;
        }

        return isset( $image_piece[1] ) ? $image_piece[1] : null;
    }

    /**
     * Create a nonce field.
     *
     * @since  3.5.0
     *
     * @see  wp_nonce_field()
     * @see  plugin_basename()
     *
     * @codeCoverageIgnore
     *
     * @param  string $key Nonce key.
     *
     * @return string
     */
    protected function nonce_field( $key ) {
        return wp_nonce_field( plugin_basename( __FILE__ ), $key, true, false );
    }

    /**
     * Featured meta box as seen in the admin.
     *
     * @since 1.0.0
     * @access public
     *
     * @param  object $post Global post object.
     * @param  array  $featured Array containing featured image count.
     *
     * @throws Exception Medium size image not found.
     * @return void
     */
    public function featured_meta_box( $post, $featured ) {
        $featured_img         = $featured['args'][0];
        $featured_id          = is_null( $featured['args'][1] ) ? 2 : --$featured['args'][1];
        $featured_img_full    = $featured_img;
        $featured_img_trimmed = $featured_img;

        if ( ! is_null( $featured_img ) ) {
            $featured_img_trimmed = $this->separate( $featured_img );
            $featured_img_full    = $this->separate( $featured_img, 'full' );
        }

        $thumbnail     = null;
        $attachment_id = null;
        if ( ! empty( $featured_img_full ) ) {
            $attachment_id = $this->get_image_id( $this->uploadUrl . $featured_img_full );

            $thumbnail = $this->get_image_thumb_by_attachment_id( $attachment_id, 'medium' );

            if ( empty( $thumbnail ) ) {
                // since medium sized thumbnail image is missing,
                // let's set full image url as thumbnail.
                $thumbnail = $featured_img_full;
            }
        }

        // Add a nonce field.
        echo $this->nonce_field( 'dfi_fimageplug-' . $featured_id ); // WPCS: XSS ok.
        echo $this->get_featured_box( $featured_img_trimmed, $featured_img, $featured_id, $thumbnail, $post->ID, $attachment_id ); // WPCS: XSS ok.
    }

    /**
     * Returns featured box html content.
     *
     * @since  3.1.0
     * @access private
     *
     * @param string $featured_img_trimmed Medium sized image.
     * @param string $featured_img         Full sized image.
     * @param string $featured_id          Featured id number for translation.
     * @param string $thumbnail            Thumb sized image.
     * @param int    $post_id              Post id.
     * @param int    $attachment_id        Attachment id.
     *
     * @return string Html content
     */
    private function get_featured_box( $featured_img_trimmed, $featured_img, $featured_id, $thumbnail, $post_id, $attachment_id ) {
        $has_featured_image = ! empty( $featured_img_trimmed ) ? ' hasFeaturedImage' : '';
        $thumbnail          = ! is_null( $thumbnail ) ? $thumbnail : '';
        $dfi_empty          = is_null( $featured_img_trimmed ) ? 'dfiImgEmpty' : '';

        return "
            <a 
                href='javascript:void(0)'
                class='dfiFeaturedImage{$has_featured_image}'
                title='" . __( 'Set Featured Image', self::TEXT_DOMAIN ) . "'
                data-post-id='" . $post_id . "'
                data-attachment-id='" . $attachment_id . "'
            >
                <span class='dashicons dashicons-camera'></span>
            </a>
            <img src='" . $thumbnail . "' class='dfiImg {$dfi_empty}'/>
            <div class='dfiClearFloat'></div>
            <input type='hidden' name='dfiFeatured[]' value='{$featured_img}'  class='dfiImageHolder' />";
    }

    /**
     * Load new featured meta box via ajax.
     *
     * @since 1.0.0
     * @access public
     *
     * @return void
     */
    public function ajax_callback() {
        check_ajax_referer( plugin_basename( __FILE__ ), 'security' );

        $featured_id = isset( $_POST['id'] ) ? intval( wp_unslash( $_POST['id'] ) ) : null;

        if ( ! is_numeric( $featured_id ) ) {
            return;
        }

        // @codingStandardsIgnoreStart
        echo $this->nonce_field( 'dfi_fimageplug-' . $featured_id );
        ?>
        <a 
            href="javascript:void(0)"
            class="dfiFeaturedImage"
            title="<?php echo __( 'Set Featured Image', self::TEXT_DOMAIN ) ?>"
        >
            <span class="dashicons dashicons-camera"></span>
        </a>
        <img src="" class="dfiImg dfiImgEmpty"/>
        <div class="dfiLinks">
            <a
                href="javascript:void(0)"
                data-id="<?php echo $featured_id ?>"
                data-id-local="<?php echo $this->get_number_translation( $featured_id + 1 ) ?>"
                class="dfiAddNew dashicons dashicons-plus"
                title="<?php echo __( 'Add New', self::TEXT_DOMAIN ) ?>"
            ></a>
            <a
                href="javascript:void(0)"
                class="dfiRemove dashicons dashicons-minus"
                title="<?php echo __( 'Remove', self::TEXT_DOMAIN ) ?>"
            ></a>
        </div>
        <div class="dfiClearFloat"></div>
        <input type="hidden" name="dfiFeatured[]" value="" class="dfiImageHolder" />
        <?php
        // @codingStandardsIgnoreEnd
        wp_die( '' );
    }

    /**
     * Add custom class 'featured-meta-box' to meta box.
     *
     * @since 1.0.0
     * @access public
     *
     * @see  add_metabox_classes
     *
     * @param array $classes Classes to add in the meta box.
     *
     * @return array
     */
    public function add_metabox_classes( $classes ) {
        array_push( $classes, 'featured-meta-box' );

        return $classes;
    }

    /**
     * Add custom fields in media uploader.
     *
     * @since  3.4.0
     *
     * @param array $form_fields Fields to include in media attachment form.
     * @param array $post Post data.
     *
     * @return array
     */
    public function media_attachment_custom_fields( $form_fields, $post ) {
        $form_fields['dfi-link-to-image'] = [
            'label' => __( 'Link to Image', self::TEXT_DOMAIN ),
            'input' => 'text',
            'value' => get_post_meta( $post->ID, '_dfi_link_to_image', true ),
        ];

        return $form_fields;
    }

    /**
     * Save values of media uploader custom fields.
     *
     * @since 3.4.0
     *
     * @param array $post Post data for database.
     * @param array $attachment Attachment fields from $_POST form.
     *
     * @return array
     */
    public function media_attachment_custom_fields_save( $post, $attachment ) {
        if ( isset( $attachment['dfi-link-to-image'] ) ) {
            update_post_meta( $post['ID'], '_dfi_link_to_image', $attachment['dfi-link-to-image'] );
        }

        return $post;
    }

    /**
     * Update featured images in the database.
     *
     * @since 1.0.0
     * @access public
     *
     * @see  plugin_basename()
     * @see  update_post_meta()
     * @see  current_user_can()
     *
     * @param  int $post_id Current post id.
     *
     * @return bool|null
     */
    public function save_meta( $post_id ) {
        // Check auto save.
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return false;
        }

        if ( ! $this->verify_nonces() ) {
            return false;
        }

        // Check permission before saving data.
        if ( current_user_can( 'edit_posts', $post_id ) && isset( $_POST['dfiFeatured'] ) ) { // WPCS: CSRF ok.
            $featured_images = is_array( $_POST['dfiFeatured'] ) ? $_POST['dfiFeatured'] : []; // WPCS: sanitization ok, CSRF ok.

            update_post_meta( $post_id, 'dfiFeatured', $this->sanitize_array( $featured_images ) );
        }
    }

    /**
     * Sanitize array.
     *
     * @since 3.6.0
     * @access protected
     *
     * @param array $input_array Input array.
     *
     * @return array
     */
    protected function sanitize_array( $input_array ) {
        $sanitized = [];

        foreach ( $input_array as $value ) {
            $sanitized[] = sanitize_text_field( wp_unslash( $value ) );
        }

        return $sanitized;
    }

    /**
     * Verify metabox nonces.
     *
     * @access protected
     * @see  wp_verify_nonce()
     *
     * @return bool
     */
    protected function verify_nonces() {
        $keys = preg_grep( '/dfi_fimageplug-\d+$/', array_keys( $_POST ) ); // WPCS: CSRF ok.

        if ( empty( $keys ) ) {
            return false;
        }

        foreach ( $keys as $key ) {
            // Verify nonce.
            if ( ! isset( $_POST[ $key ] ) ||
                 ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ $key ] ) ), plugin_basename( __FILE__ ) )
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Execute query.
     *
     * @param string $query Query to execute.
     *
     * @return null|string
     */
    private function execute_query( $query ) {
        return $this->db->get_var( $query );
    }

    /**
     * Get attachment id of the image by image url.
     *
     * @since 3.1.7
     * @access protected
     * @global object $wpdb
     *
     * @param  string $image_url URL of an image.
     *
     * @return string
     */
    protected function get_attachment_id( $image_url ) {
        return $this->execute_query( $this->db->prepare( 'SELECT ID FROM ' . $this->db->posts . ' WHERE guid = %s', $image_url ) );
    }

    /**
     * Get image url of the image by attachment id.
     *
     * @since 2.0.0
     * @access public
     *
     * @see  wp_get_attachment_image_src()
     *
     * @param  int    $attachment_id attachment id of an image.
     * @param  string $size size of the image to fetch (thumbnail, medium, full).
     *
     * @return string
     */
    public function get_image_url( $attachment_id, $size = 'full' ) {
        $image_thumb = wp_get_attachment_image_src( $attachment_id, $size );

        return empty( $image_thumb ) ? null : $image_thumb[0];
    }

    /**
     * Get image thumbnail url of specific size by attachment id.
     *
     * @since 3.7.0
     * @access public
     *
     * @see wp_get_attachment_image_src()
     *
     * @param int $attachment_id attachment id of an image.
     * @param string $size size of the image to fetch (thumbnail, medium, full).
     *
     * @return string|null
     */
    public function get_image_thumb_by_attachment_id( $attachment_id, $size = 'thumbnail' ) {
        if ( empty( $attachment_id ) ) {
            return null;
        }

        $image_thumb = wp_get_attachment_image_src( $attachment_id, $size );

        return empty( $image_thumb ) ? null : $image_thumb[0];
    }

    /**
     * Get image thumbnail url of specific size by image url.
     *
     * @since 2.0.0
     * @access public
     *
     * @see  get_image_id()
     * @see  wp_get_attachment_image_src()
     *
     * @param  string $image_url url of an image.
     * @param  string $size size of the image to fetch (thumbnail, medium, full).
     *
     * @return string
     */
    public function get_image_thumb( $image_url, $size = 'thumbnail' ) {
        $attachment_id = $this->get_image_id( $image_url );
        $image_thumb   = wp_get_attachment_image_src( $attachment_id, $size );

        return empty( $image_thumb ) ? null : $image_thumb[0];
    }

    /**
     * Gets attachment id from given image url.
     *
     * @param  string $image_url url of an image.
     *
     * @since  2.0.0
     * @access public
     *
     * @return int|null attachment id of an image
     */
    public function get_image_id( $image_url ) {
        $attachment_id = $this->get_attachment_id( $image_url );

        if ( is_null( $attachment_id ) ) {
            /*
             * Check if the image is an edited image.
             * and try to get the attachment id.
             */

            global $wp_version;

            if ( intval( $wp_version ) >= 4 ) {
                return attachment_url_to_postid( $image_url );
            }

            // Fallback.
            $image_url = str_replace( $this->uploadUrl . '/', '', $image_url );

            $row = $this->execute_query( $this->db->prepare( 'SELECT post_id FROM ' . $this->db->postmeta . ' WHERE meta_key = %s AND meta_value = %s', '_wp_attached_file', $image_url ) );
            if ( ! is_null( $row ) ) {
                $attachment_id = $row;
            }
        }

        return $attachment_id;
    }

    /**
     * Get image title.
     *
     * @since 2.0.0
     * @access public
     *
     * @param string $image_url URL of an image.
     *
     * @return string
     */
    public function get_image_title( $image_url ) {
        return $this->execute_query( $this->db->prepare( 'SELECT post_title FROM ' . $this->db->posts . ' WHERE guid = %s', $image_url ) );
    }

    /**
     * Get image title by id.
     *
     * @since 2.0.0
     * @access public
     *
     * @param  int $attachment_id Attachment id of an image.
     *
     * @return string
     */
    public function get_image_title_by_id( $attachment_id ) {
        return $this->execute_query( $this->db->prepare( 'SELECT post_title FROM ' . $this->db->posts . ' WHERE ID = %d', $attachment_id ) );
    }

    /**
     * Get image caption.
     *
     * @since 2.0.0
     * @access public
     *
     * @param  string $image_url URL of an image.
     *
     * @return string
     */
    public function get_image_caption( $image_url ) {
        return $this->execute_query( $this->db->prepare( 'SELECT post_excerpt FROM ' . $this->db->posts . ' WHERE guid = %s', $image_url ) );
    }

    /**
     * Get image caption by id.
     *
     * @since 2.0.0
     * @access public
     *
     * @param  int $attachment_id Attachment id of an image.
     *
     * @return string
     */
    public function get_image_caption_by_id( $attachment_id ) {
        return $this->execute_query( $this->db->prepare( 'SELECT post_excerpt FROM ' . $this->db->posts . ' WHERE ID = %d', $attachment_id ) );
    }

    /**
     * Get image alternate text.
     *
     * @since 2.0.0
     * @access public
     *
     * @see  get_post_meta()
     *
     * @param  string $image_url URL of an image.
     *
     * @return string
     */
    public function get_image_alt( $image_url ) {
        $attachment = $this->db->get_col( $this->db->prepare( 'SELECT ID FROM ' . $this->db->posts . ' WHERE guid = %s', $image_url ) );

        $alt = null;
        if ( ! empty( $attachment ) ) {
            $alt = get_post_meta( $attachment[0], '_wp_attachment_image_alt' );
        }

        return ( is_null( $alt ) || empty( $alt ) ) ? null : $alt[0];
    }

    /**
     * Get image alternate text by attachment id.
     *
     * @since 2.0.0
     * @access public
     *
     * @see  get_post_meta()
     *
     * @param  int $attachment_id Attachment id of an image.
     *
     * @return string
     */
    public function get_image_alt_by_id( $attachment_id ) {
        $alt = get_post_meta( $attachment_id, '_wp_attachment_image_alt' );

        return empty( $alt ) ? null : $alt[0];
    }

    /**
     * Get image description.
     *
     * @since 3.0.0
     * @access public
     *
     * @param  string $image_url URL of an image.
     *
     * @return string
     */
    public function get_image_description( $image_url ) {
        return $this->execute_query( $this->db->prepare( 'SELECT post_content FROM ' . $this->db->posts . ' WHERE guid = %s', $image_url ) );
    }

    /**
     * Get image description by id.
     *
     * @since 3.0.0
     * @access public
     *
     * @param  int $attachment_id attachment id of an image.
     *
     * @return string
     */
    public function get_image_description_by_id( $attachment_id ) {
        return $this->execute_query( $this->db->prepare( 'SELECT post_content FROM ' . $this->db->posts . ' WHERE ID = %d', $attachment_id ) );
    }

    /**
     * Get link to image.
     *
     * @since 3.4.0
     * @access public
     *
     * @param  int $attachment_id Attachment id of an image.
     *
     * @return string|null
     */
    public function get_link_to_image( $attachment_id ) {
        return get_post_meta( $attachment_id, '_dfi_link_to_image', true );
    }

    /**
     * Get all attachment ids of the post.
     *
     * @since 2.0.0
     * @access public
     *
     * @see  get_post_meta()
     *
     * @param  int $post_id id of the current post.
     *
     * @return array
     */
    public function get_post_attachment_ids( $post_id ) {
        $dfi_images = get_post_meta( $post_id, 'dfiFeatured', true );
        $ret_val    = [];

        if ( ! empty( $dfi_images ) && is_array( $dfi_images ) ) {
            foreach ( $dfi_images as $dfi_image ) {
                $dfi_image_full = $this->separate( $dfi_image, 'full' );
                $ret_val[]      = (int) $this->get_image_id( $this->uploadUrl . $dfi_image_full );
            }
        }

        return $ret_val;
    }

    /**
     * Get real post id.
     *
     * @since 3.6.0
     * @access protected
     *
     * @param int|null $post_id Post id.
     *
     * @return int|null
     */
    protected function get_real_post_id( $post_id = null ) {
        if ( ! is_null( $post_id ) && is_numeric( $post_id ) ) {
            return $post_id;
        }

        global $post;

        return $post->ID;
    }

    /**
     * Fetches featured image data of nth position.
     *
     * @since  3.0.0
     * @access  public
     *
     * @see  get_featured_images()
     *
     * @param  int $position Position of the featured image.
     * @param  int $post_id Current post id.
     *
     * @return array if found, null otherwise.
     */
    public function get_nth_featured_image( $position, $post_id = null ) {
        $post_id = $this->get_real_post_id( ( $post_id ) );

        $featured_images = $this->get_featured_images( $post_id );

        return isset( $featured_images[ $position - 2 ] ) ? $featured_images[ $position - 2 ] : null;
    }

    /**
     * Check if the image is attached with the particular post.
     *
     * @since 2.0.0
     * @access public
     *
     * @see  get_post_attachment_ids()
     *
     * @param  int $attachment_id Attachment id of an image.
     * @param  int $post_id Current post id.
     *
     * @return bool
     */
    public function is_attached( $attachment_id, $post_id ) {
        if ( empty( $attachment_id ) ) {
            return false;
        }

        $attachment_ids = $this->get_post_attachment_ids( $post_id );

        return in_array( $attachment_id, $attachment_ids, true ) ? true : false;
    }

    /**
     * Retrieve featured images for specific post(s).
     *
     * @since 2.0.0
     * @access public
     *
     * @see get_post_meta()
     *
     * @param  int $post_id id of the current post.
     *
     * @return array
     */
    public function get_featured_images( $post_id = null ) {
        $post_id    = $this->get_real_post_id( $post_id );
        $dfi_images = get_post_meta( $post_id, 'dfiFeatured', true );
        $ret_images = [];

        if ( ! empty( $dfi_images ) && is_array( $dfi_images ) ) {
            $dfi_images = array_filter( $dfi_images );

            $count = 0;
            foreach ( $dfi_images as $dfi_image ) {
                $dfi_image_trimmed = $this->separate( $dfi_image );
                $dfi_image_full    = $this->separate( $dfi_image, 'full' );

                try {
                    $ret_images[ $count ]['thumb']         = $this->get_real_upload_path( $dfi_image_trimmed );
                    $ret_images[ $count ]['full']          = $this->get_real_upload_path( $dfi_image_full );
                    $ret_images[ $count ]['attachment_id'] = $this->get_image_id( $ret_images[ $count ]['full'] );
                } catch ( Exception $e ) {
                    /* Ignore the exception and continue with other featured images */
                }

                $count ++;
            }
        }

        return $ret_images;
    }

    /**
     * Check to see if the upload url is already available in path.
     *
     * @since  3.1.14
     * @access protected
     *
     * @param  string $img Uploaded image.
     *
     * @return string
     */
    protected function get_real_upload_path( $img ) {
        // check if upload path is already attached.
        if ( false !== strpos( $img, $this->uploadUrl ) || preg_match( '/https?:\/\//', $img ) ) {
            return $img;
        }

        return $this->uploadUrl . $img;
    }

    /**
     * Retrieve featured images for specific post(s) including the default Featured Image.
     *
     * @since 3.1.7
     * @access public
     *
     * @see  $this->get_featured_images()
     *
     * @param int $post_id Current post id.
     *
     * @return array An array of images or an empty array on failure
     */
    public function get_all_featured_images( $post_id = null ) {
        $post_id      = $this->get_real_post_id( $post_id );
        $thumbnail_id = get_post_thumbnail_id( $post_id );
        $all_images   = [];

        if ( ! empty( $thumbnail_id ) ) {
            $featured_image         = [
                'thumb'         => wp_get_attachment_thumb_url( $thumbnail_id ),
                'full'          => wp_get_attachment_url( $thumbnail_id ),
                'attachment_id' => $thumbnail_id,
            ];

            $all_images[] = $featured_image;
        }

        return array_merge( $all_images, $this->get_featured_images( $post_id ) );
    }

    /**
     * Load the plugin's textdomain hooked to 'plugins_loaded'.
     */
    public function load_plugin_textdomain() {
        load_plugin_textdomain(
            self::TEXT_DOMAIN,
            false,
            dirname( plugin_basename( __FILE__ ) ) . '/languages/'
        );
    }
}

/**
 * Instantiate the main class.
 *
 * @var GADynamicFeaturedImage $dynamic_featured_image holds the instantiated class {@uses GADynamicFeaturedImage}
 */
global $dynamic_featured_image;
$dynamic_featured_image = new GADynamicFeaturedImage();
