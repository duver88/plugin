<?php
/**
 * Plugin Name: Idea Wall Pro v3 (Ziteboard Edition)
 * Description: Pizarrones visuales con pan/zoom, edición inline, emojis/colores, múltiples pizarrones, shortcode con ID para copiar, fondo solo admin, activación por tablero, auto-actualización sin recargar. Shortcode: [idea_wall id="123" theme="auto|light|dark"]
 * Version: 3.0.0
 * Author: ChatGPT
 * License: GPLv2 or later
 */

if (!defined('ABSPATH')) exit;

class IWP_V3 {
  const CPT_WALL = 'idea_wall';
  const CPT_NOTE = 'idea_note';
  const NONCE = 'iwp_v3_nonce';

  public function __construct(){
    add_action('init', [$this,'register_cpts']);
    add_action('add_meta_boxes', [$this,'meta_boxes']);
    add_action('save_post', [$this,'save_wall_meta'], 10, 2);
    add_filter('manage_edit-idea_wall_columns', [$this,'wall_columns']);
    add_action('manage_idea_wall_posts_custom_column', [$this,'wall_columns_content'], 10, 2);

    add_shortcode('idea_wall', [$this,'shortcode']);

    add_action('wp_enqueue_scripts', [$this,'enqueue_front']);
    add_action('admin_enqueue_scripts', [$this,'enqueue_admin']);

    // AJAX
    add_action('wp_ajax_iwp_v3_list',  [$this,'ajax_list']);
    add_action('wp_ajax_nopriv_iwp_v3_list',  [$this,'ajax_list']);
    add_action('wp_ajax_iwp_v3_add',   [$this,'ajax_add']);
    add_action('wp_ajax_nopriv_iwp_v3_add',   [$this,'ajax_add']);
    add_action('wp_ajax_iwp_v3_move',  [$this,'ajax_move']); // admin
    add_action('wp_ajax_iwp_v3_resize',[$this,'ajax_resize']); // admin
    add_action('wp_ajax_iwp_v3_update_text',[$this,'ajax_update_text']); // admin
    add_action('wp_ajax_iwp_v3_delete',[$this,'ajax_delete']); // admin

    register_activation_hook(__FILE__, [$this,'on_activate']);
  }

  public function register_cpts(){
    register_post_type(self::CPT_WALL, [
      'labels' => [
        'name' => 'Pizarrones',
        'singular_name' => 'Pizarrón',
        'menu_name' => 'Idea Wall Pro',
        'add_new_item' => 'Crear pizarrón',
        'edit_item' => 'Editar pizarrón',
      ],
      'public' => false,
      'show_ui' => true,
      'supports' => ['title','custom-fields'],
      'menu_icon' => 'dashicons-art',
    ]);

    register_post_type(self::CPT_NOTE, [
      'labels' => [
        'name' => 'Notas',
        'singular_name' => 'Nota',
      ],
      'public' => false,
      'show_ui' => true,
      'supports' => ['title','editor','author','custom-fields'],
      'show_in_menu' => 'edit.php?post_type=' . self::CPT_WALL,
      'menu_icon' => 'dashicons-sticky',
    ]);
  }

  public function meta_boxes(){
    add_meta_box('iwp_wall_settings', 'Ajustes de Pizarrón', [$this,'wall_meta_box'], self::CPT_WALL, 'normal', 'default');
  }

  public function wall_meta_box($post){
    $width = intval(get_post_meta($post->ID,'iwp_width',true) ?: 1600);
    $height= intval(get_post_meta($post->ID,'iwp_height',true) ?: 1000);
    $bgc   = get_post_meta($post->ID,'iwp_bg_color',true) ?: '#f5f7fb';
    $bgi   = get_post_meta($post->ID,'iwp_bg_image',true) ?: '';
    $active= get_post_meta($post->ID,'iwp_active',true) ?: '1';
    $short = '[idea_wall id="'.$post->ID.'" theme="auto"]';
    ?>
    <p><strong>ID del pizarrón:</strong> <?php echo intval($post->ID); ?></p>
    <p><strong>Shortcode:</strong> <code id="iwp_sc"><?php echo esc_html($short); ?></code>
      <button type="button" class="button" onclick="navigator.clipboard.writeText(document.getElementById('iwp_sc').textContent)">Copiar</button>
    </p>
    <table class="form-table">
      <tr>
        <th scope="row">Activo</th>
        <td><label><input type="checkbox" name="iwp_active" value="1" <?php checked($active,'1'); ?>> Mostrar públicamente (permite nuevas notas)</label></td>
      </tr>
      <tr>
        <th scope="row">Tamaño</th>
        <td>
          Ancho: <input type="number" name="iwp_width" value="<?php echo esc_attr($width); ?>" min="800" step="10"> px
          &nbsp; Alto: <input type="number" name="iwp_height" value="<?php echo esc_attr($height); ?>" min="600" step="10"> px
        </td>
      </tr>
      <tr>
        <th scope="row">Fondo (solo admin)</th>
        <td>
          Color: <input type="text" name="iwp_bg_color" value="<?php echo esc_attr($bgc); ?>" class="regular-text" placeholder="#f5f7fb">
          <br>Imagen (URL): <input type="url" name="iwp_bg_image" value="<?php echo esc_attr($bgi); ?>" class="large-text" placeholder="https://...">
          <p class="description">Solo administradores pueden cambiar el fondo. Los visitantes no verán ni podrán modificar estos campos.</p>
        </td>
      </tr>
    </table>
    <?php
    wp_nonce_field('iwp_wall_save','iwp_wall_nonce');
  }

  public function save_wall_meta($post_id, $post){
    if ($post->post_type !== self::CPT_WALL) return;
    if (!isset($_POST['iwp_wall_nonce']) || !wp_verify_nonce($_POST['iwp_wall_nonce'],'iwp_wall_save')) return;
    if (!current_user_can('manage_options')) return;

    update_post_meta($post_id, 'iwp_active', isset($_POST['iwp_active']) ? '1' : '0');
    update_post_meta($post_id, 'iwp_width',  intval($_POST['iwp_width'] ?? 1600));
    update_post_meta($post_id, 'iwp_height', intval($_POST['iwp_height'] ?? 1000));
    update_post_meta($post_id, 'iwp_bg_color', sanitize_text_field($_POST['iwp_bg_color'] ?? '#f5f7fb'));
    update_post_meta($post_id, 'iwp_bg_image', esc_url_raw($_POST['iwp_bg_image'] ?? ''));
  }

  public function wall_columns($cols){
    $cols['iwp_id'] = 'ID';
    $cols['iwp_shortcode'] = 'Shortcode';
    $cols['iwp_active'] = 'Activo';
    return $cols;
  }
  public function wall_columns_content($col, $post_id){
    if ($col==='iwp_id'){ echo intval($post_id); }
    if ($col==='iwp_shortcode'){ echo '<code>[idea_wall id="'.intval($post_id).'" theme="auto"]</code>'; }
    if ($col==='iwp_active'){ echo get_post_meta($post_id,'iwp_active',true)==='1' ? 'Sí' : 'No'; }
  }

  public function enqueue_front(){
    if (!is_singular() && !is_front_page() && !is_home()) return;
    global $post;
    if (!$post || strpos((string)$post->post_content, '[idea_wall')===false) return;
    wp_enqueue_style('iwp-v3', plugins_url('assets/css/iwp-v3.css', __FILE__), [], '3.0.0');
    // React via CDN
    wp_enqueue_script('react', 'https://unpkg.com/react@18/umd/react.production.min.js', [], '18', true);
    wp_enqueue_script('react-dom', 'https://unpkg.com/react-dom@18/umd/react-dom.production.min.js', ['react'], '18', true);
    wp_enqueue_script('iwp-v3', plugins_url('assets/js/iwp-v3.js', __FILE__), ['react','react-dom'], '3.0.0', true);
    wp_localize_script('iwp-v3', 'IWPV3', [
      'ajax' => admin_url('admin-ajax.php'),
      'nonce'=> wp_create_nonce(self::NONCE),
      'isAdmin' => current_user_can('manage_options'),
    ]);
  }

  public function enqueue_admin($hook=''){
    if (strpos($hook, self::CPT_WALL)===false) return;
    wp_enqueue_style('iwp-v3', plugins_url('assets/css/iwp-v3.css', __FILE__), [], '3.0.0');
    wp_enqueue_script('react', 'https://unpkg.com/react@18/umd/react.production.min.js', [], '18', true);
    wp_enqueue_script('react-dom', 'https://unpkg.com/react-dom@18/umd/react-dom.production.min.js', ['react'], '18', true);
    wp_enqueue_script('iwp-v3', plugins_url('assets/js/iwp-v3.js', __FILE__), ['react','react-dom'], '3.0.0', true);
    wp_localize_script('iwp-v3', 'IWPV3', [
      'ajax' => admin_url('admin-ajax.php'),
      'nonce'=> wp_create_nonce(self::NONCE),
      'isAdmin' => true,
    ]);
  }

  public function shortcode($atts){
    $atts = shortcode_atts(['id'=>0,'theme'=>'auto'], $atts, 'idea_wall');
    $wall_id = intval($atts['id']);
    if (!$wall_id || get_post_type($wall_id)!==self::CPT_WALL) return '<div class="iwp-msg">Shortcode mal configurado.</div>';
    $active = get_post_meta($wall_id,'iwp_active',true);
    if ($active!=='1') return '<div class="iwp-msg">Este pizarrón está desactivado temporalmente.</div>';
    $width  = intval(get_post_meta($wall_id,'iwp_width',true) ?: 1600);
    $height = intval(get_post_meta($wall_id,'iwp_height',true) ?: 1000);
    $bgc    = get_post_meta($wall_id,'iwp_bg_color',true) ?: '#f5f7fb';
    $bgi    = get_post_meta($wall_id,'iwp_bg_image',true) ?: '';
    ob_start(); ?>
      <div class="iwp-root iwp-theme-<?php echo esc_attr($atts['theme']); ?>"
           data-wall="<?php echo esc_attr($wall_id); ?>"
           data-width="<?php echo esc_attr($width); ?>"
           data-height="<?php echo esc_attr($height); ?>"
           data-bgc="<?php echo esc_attr($bgc); ?>"
           data-bgi="<?php echo esc_attr($bgi); ?>">
        <div id="iwp-app-<?php echo esc_attr($wall_id); ?>"></div>
      </div>
    <?php return ob_get_clean();
  }

  // ---------- AJAX HANDLERS ----------
  private function get_notes($wall_id){
    $q = new WP_Query([
      'post_type' => self::CPT_NOTE,
      'post_status' => 'publish',
      'posts_per_page' => 1000,
      'meta_query' => [[ 'key'=>'iwp_wall_id','value'=>$wall_id,'compare'=>'=' ]],
      'orderby'=>'date','order'=>'DESC',
      'no_found_rows'=>true,
    ]);
    $items = [];
    while ($q->have_posts()){ $q->the_post();
      $id = get_the_ID();
      $items[] = [
        'id'=>$id,
        'text'=> get_post_field('post_content',$id),
        'x'=> intval(get_post_meta($id,'iwp_x',true)),
        'y'=> intval(get_post_meta($id,'iwp_y',true)),
        'w'=> intval(get_post_meta($id,'iwp_w',true) ?: 220),
        'h'=> intval(get_post_meta($id,'iwp_h',true) ?: 110),
        'color'=> get_post_meta($id,'iwp_color',true) ?: '#fff59d',
        'font'=> get_post_meta($id,'iwp_font',true) ?: 'system-ui',
        'size'=> intval(get_post_meta($id,'iwp_size',true) ?: 18),
        'style'=> get_post_meta($id,'iwp_style',true) ?: 'postit', // postit|minimal|bubble
        'author'=> get_post_meta($id,'iwp_author',true) ?: 'Anónimo',
        'date'=> get_post_time('U', true, $id),
      ];
    }
    wp_reset_postdata();
    return $items;
  }

  public function ajax_list(){
    check_ajax_referer(self::NONCE, '_ajax_nonce');
    $wall_id = intval($_POST['wall'] ?? 0);
    if (!$wall_id || get_post_type($wall_id)!==self::CPT_WALL) wp_send_json_error(['message'=>'Pizarrón inválido'],400);
    wp_send_json_success(['items'=>$this->get_notes($wall_id)]);
  }

  public function ajax_add(){
    check_ajax_referer(self::NONCE, '_ajax_nonce');
    $wall_id = intval($_POST['wall'] ?? 0);
    if (!$wall_id || get_post_type($wall_id)!==self::CPT_WALL) wp_send_json_error(['message'=>'Pizarrón inválido'],400);
    $active = get_post_meta($wall_id,'iwp_active',true);
    if ($active!=='1') wp_send_json_error(['message'=>'Pizarrón inactivo'],403);
    $text = isset($_POST['text']) ? wp_strip_all_tags(wp_unslash($_POST['text'])) : '';
    if (mb_strlen($text)<1 || mb_strlen($text)>500) wp_send_json_error(['message'=>'Texto inválido'],400);
    $x=intval($_POST['x']??0); $y=intval($_POST['y']??0);
    $w=intval($_POST['w']??220); $h=intval($_POST['h']??110);
    $color=sanitize_hex_color($_POST['color']??'#fff59d') ?: '#fff59d';
    $font =sanitize_text_field($_POST['font']??'system-ui');
    $size =intval($_POST['size']??18);
    $style=sanitize_text_field($_POST['style']??'postit');

    $id = wp_insert_post([
      'post_type'=>self::CPT_NOTE,'post_status'=>'publish',
      'post_title'=>mb_substr($text,0,48),'post_content'=>$text,
      'meta_input'=>[
        'iwp_wall_id'=>$wall_id,'iwp_x'=>$x,'iwp_y'=>$y,'iwp_w'=>$w,'iwp_h'=>$h,
        'iwp_color'=>$color,'iwp_font'=>$font,'iwp_size'=>$size,'iwp_style'=>$style,
        'iwp_ip'=>isset($_SERVER['REMOTE_ADDR'])?sanitize_text_field($_SERVER['REMOTE_ADDR']):'',
      ]
    ], true);
    if (is_wp_error($id)) wp_send_json_error(['message'=>'No se pudo guardar'],500);
    wp_send_json_success(['id'=>$id]);
  }

  public function ajax_move(){
    if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'Forbidden'],403);
    check_ajax_referer(self::NONCE, '_ajax_nonce');
    $id=intval($_POST['id']??0); $x=intval($_POST['x']??0); $y=intval($_POST['y']??0);
    if (!$id || get_post_type($id)!==self::CPT_NOTE) wp_send_json_error(['message'=>'Invalid'],400);
    update_post_meta($id,'iwp_x',$x); update_post_meta($id,'iwp_y',$y);
    wp_send_json_success(['ok'=>true]);
  }

  public function ajax_resize(){
    if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'Forbidden'],403);
    check_ajax_referer(self::NONCE, '_ajax_nonce');
    $id=intval($_POST['id']??0); $w=intval($_POST['w']??220); $h=intval($_POST['h']??110);
    if (!$id || get_post_type($id)!==self::CPT_NOTE) wp_send_json_error(['message'=>'Invalid'],400);
    update_post_meta($id,'iwp_w',$w); update_post_meta($id,'iwp_h',$h);
    wp_send_json_success(['ok'=>true]);
  }

  public function ajax_update_text(){
    if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'Forbidden'],403);
    check_ajax_referer(self::NONCE, '_ajax_nonce');
    $id=intval($_POST['id']??0); $text=wp_strip_all_tags(wp_unslash($_POST['text']??''));
    if (!$id || get_post_type($id)!==self::CPT_NOTE) wp_send_json_error(['message'=>'Invalid'],400);
    if (mb_strlen($text)<1 || mb_strlen($text)>500) wp_send_json_error(['message'=>'Texto inválido'],400);
    wp_update_post(['ID'=>$id,'post_content'=>$text,'post_title'=>mb_substr($text,0,48)]);
    wp_send_json_success(['ok'=>true]);
  }

  public function ajax_delete(){
    if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'Forbidden'],403);
    check_ajax_referer(self::NONCE, '_ajax_nonce');
    $id=intval($_POST['id']??0); if (!$id || get_post_type($id)!==self::CPT_NOTE) wp_send_json_error(['message'=>'Invalid'],400);
    wp_delete_post($id,true); wp_send_json_success(['ok'=>true]);
  }

  public function on_activate(){
    $wall = wp_insert_post([
      'post_type'=>self::CPT_WALL,'post_title'=>'Pizarrón Demo v3','post_status'=>'publish'
    ]);
    if ($wall && !is_wp_error($wall)){
      update_post_meta($wall,'iwp_active','1');
      update_post_meta($wall,'iwp_width',1600);
      update_post_meta($wall,'iwp_height',1000);
      update_post_meta($wall,'iwp_bg_color','#f5f7fb');
      update_post_meta($wall,'iwp_bg_image','');
      wp_insert_post([
        'post_type'=>'page','post_title'=>'Idea Wall v3 Demo',
        'post_status'=>'draft','post_content'=>'[idea_wall id="'.$wall.'" theme="auto"]'
      ]);
    }
  }
}
new IWP_V3();
