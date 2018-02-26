<?php
/**
 * Plugin Name: Bowtie Social Cache
 * Plugin URI: https://github.com/theinfiniteagency/bowtie-social-cache
 * Description: Fetches and Caches Social Media Feeds
 * Version: 1.0.0
 * Author: The Infinite Agency
 * Author URI: http://theinfiniteagency.com
 *
 */
class Social {
    public $posts = array();
    public $source = 'twitter';
    public $user_id = false;
    public $key = false;

    private $public_key = false;
    private $secret_key = false;

    public function __construct($source, $args = array()) {
        $this->source = $source;
        $this->user_id = $args['user_id'] ? $args['user_id'] : false;
        $this->key = $this->source.'_'.$this->user_id.'_feed';
    }

    public function get() {
        $data = get_transient($this->key);
        if ($data === false) {
            return $this->fetch();
        }

        return json_decode($data);
    }

    public function authenticate($authorization_code) {
        switch($this->source) {
            case 'instagram':
                $args = array(
                    'body' => array(
                        'client_id' => get_field('instagram_client_id', 'options'),
                        'client_secret' => get_field('instagram_client_secret', 'options'),
                        'grant_type' => 'authorization_code',
                        'redirect_uri' => get_site_url() . '/wp-json/bowtie/auth/instagram',
                        'code' => $authorization_code
                    ),
                );
                
                $request = wp_remote_post('https://api.instagram.com/oauth/access_token', $args);
                
                $body = json_decode($request['body']);

                if($body->access_token) {
                    $this->set_access_token('instagram', $body->access_token);
                    return 'authenticated';
                } else {
                    return $body;
                }                

                break;
            case 'twitter':

                break;
        }
    }

    public function fetch() {
        switch($this->source) {
            case 'instagram':
                $access_token = $this->get_access_token('instagram');
                $request = wp_remote_get('https://api.instagram.com/v1/users/self/media/recent/?access_token='.$access_token);
                $body = wp_remote_retrieve_body($request);
                $data = json_decode($body);

                $this->cache($data);
                return $data;

                break;
            case 'twitter':
                $url = "https://api.twitter.com/1.1/statuses/user_timeline.json";
                $oauth = array(
                    'oauth_consumer_key' => get_field('twitter_client_id', 'options'),
                    'oauth_nonce' => time(),
                    'oauth_signature_method' => 'HMAC-SHA1',
                    'oauth_token' => get_field('twitter_access_token', 'options'),
                    'oauth_timestamp' => time(),
                    'oauth_version' => '1.0'
                );

                $base_info = $this->generate_base($url, 'GET', $oauth);
                $composite_key = rawurlencode(get_field('twitter_client_secret', 'options')) . '&' . rawurlencode(get_field('twitter_access_token_secret', 'options'));
                $oauth_signature = base64_encode(hash_hmac('sha1', $base_info, $composite_key, true));
                $oauth['oauth_signature'] = $oauth_signature;

                $args = array(
                    'headers' => array(
                        'Authorization' => $this->generate_header($oauth),
                    )
                );
                $request = wp_remote_get($url, $args);
                $body = wp_remote_retrieve_body($request);
                $data = json_decode($body);
                $this->cache($data);
                
                return $data;
                break;
        }
    }

    public function set_access_token($source, $token) {
        return update_option($source.'_access_token', $token);
    }

    public function get_access_token($source, $token) {
        return get_option($source.'_access_token');
    }

    public function generate_header($oauth) {
        $r = 'OAuth ';
        $values = array();
        foreach($oauth as $key=>$value)
            $values[] = "$key=\"" . rawurlencode($value) . "\"";
        $r .= implode(', ', $values);
        return $r;
    }

    function generate_base($baseURI, $method, $params) {
        $r = array();
        ksort($params);
        foreach($params as $key=>$value){
            $r[] = "$key=" . rawurlencode($value);
        }
        return $method."&" . rawurlencode($baseURI) . '&' . rawurlencode(implode('&', $r));
    }

    function cache($data) {
        $data = json_encode($data);
        return set_transient($this->key, $data, HOUR_IN_SECONDS);
    }
}

function bowtie_social_authenticate_instagram( WP_REST_Request $request ) {
    $code = $_GET['code'];
    if(!$code) return 'missing code';
    $instagram = new Social('instagram');
    return $instagram->authenticate($code);
}

function bowtie_social_feed( WP_REST_Request $request ) {  
    $source = 'instagram';
    if($_GET['source']) $source = $_GET['source'];

    $feed = new Social($source);
    $output = $feed->get();

    return $output;
}

add_action('rest_api_init', function () {

    register_rest_route( 'bowtie', '/auth/instagram', array(
        'methods' => 'GET',
        'callback' => 'bowtie_social_authenticate_instagram',
    ));

    register_rest_route( 'bowtie', '/feed', array(
        'methods' => 'GET',
        'callback' => 'bowtie_social_feed',
    ));

});

/*
 * Register ACF Fields to Manage Social API Keys
 * 
 */

if( function_exists('acf_add_local_field_group') ):
acf_add_local_field_group(array(
	'key' => 'group_5a5794faa5d40',
	'title' => 'Social API',
	'fields' => array(
		array(
			'key' => 'field_5a5796f988bb4',
			'label' => 'Instagram',
			'name' => '',
			'type' => 'tab',
			'instructions' => '',
			'required' => 0,
			'conditional_logic' => 0,
			'wrapper' => array(
				'width' => '',
				'class' => '',
				'id' => '',
			),
			'placement' => 'top',
			'endpoint' => 0,
		),
		array(
			'key' => 'field_5a83311cfca09',
			'label' => '',
			'name' => '',
			'type' => 'message',
			'instructions' => '',
			'required' => 0,
			'conditional_logic' => 0,
			'wrapper' => array(
				'width' => '',
				'class' => '',
				'id' => '',
			),
			'message' => '<a href="https://api.instagram.com/oauth/authorize/?client_id='.get_field('twitter_client_id', 'options').'&redirect_uri='.get_site_url().'/wp-json/bowtie/auth/instagram&response_type=code" class="button">Authenticate</a>
<p>You must be logged into the authorized Instagram account before authenticating. If you change your password, be sure to return and authenticate again.</p>',
			'new_lines' => 'wpautop',
			'esc_html' => 0,
		),
		array(
			'key' => 'field_5a57971f88bb6',
			'label' => 'Client ID',
			'name' => 'instagram_client_id',
			'type' => 'text',
			'instructions' => '',
			'required' => 0,
			'conditional_logic' => 0,
			'wrapper' => array(
				'width' => '',
				'class' => '',
				'id' => '',
			),
			'default_value' => '',
			'placeholder' => '',
			'prepend' => '',
			'append' => '',
			'maxlength' => '',
		),
		array(
			'key' => 'field_5a57972888bb7',
			'label' => 'Client Secret',
			'name' => 'instagram_client_secret',
			'type' => 'text',
			'instructions' => '',
			'required' => 0,
			'conditional_logic' => 0,
			'wrapper' => array(
				'width' => '',
				'class' => '',
				'id' => '',
			),
			'default_value' => '',
			'placeholder' => '',
			'prepend' => '',
			'append' => '',
			'maxlength' => '',
		),
		array(
			'key' => 'field_5a57970188bb5',
			'label' => 'Twitter',
			'name' => '',
			'type' => 'tab',
			'instructions' => '',
			'required' => 0,
			'conditional_logic' => 0,
			'wrapper' => array(
				'width' => '',
				'class' => '',
				'id' => '',
			),
			'placement' => 'top',
			'endpoint' => 0,
		),
		array(
			'key' => 'field_5a83b39e14f7d',
			'label' => '',
			'name' => '',
			'type' => 'message',
			'instructions' => '',
			'required' => 0,
			'conditional_logic' => 0,
			'wrapper' => array(
				'width' => '',
				'class' => '',
				'id' => '',
			),
			'message' => '<a href="https://apps.twitter.com/" target="_blank" class="button">Twitter Apps &rarr;</a>',
			'new_lines' => 'wpautop',
			'esc_html' => 0,
		),
		array(
			'key' => 'field_5a83385268a9a',
			'label' => 'Client ID',
			'name' => 'twitter_client_id',
			'type' => 'text',
			'instructions' => '',
			'required' => 0,
			'conditional_logic' => 0,
			'wrapper' => array(
				'width' => '50',
				'class' => '',
				'id' => '',
			),
			'default_value' => '',
			'placeholder' => '',
			'prepend' => '',
			'append' => '',
			'maxlength' => '',
		),
		array(
			'key' => 'field_5a833942f7118',
			'label' => 'Client Secret',
			'name' => 'twitter_client_secret',
			'type' => 'text',
			'instructions' => '',
			'required' => 0,
			'conditional_logic' => 0,
			'wrapper' => array(
				'width' => '50',
				'class' => '',
				'id' => '',
			),
			'default_value' => '',
			'placeholder' => '',
			'prepend' => '',
			'append' => '',
			'maxlength' => '',
		),
		array(
			'key' => 'field_5a83385b68a9b',
			'label' => 'Access Token',
			'name' => 'twitter_access_token',
			'type' => 'text',
			'instructions' => '',
			'required' => 0,
			'conditional_logic' => 0,
			'wrapper' => array(
				'width' => '50',
				'class' => '',
				'id' => '',
			),
			'default_value' => '',
			'placeholder' => '',
			'prepend' => '',
			'append' => '',
			'maxlength' => '',
		),
		array(
			'key' => 'field_5a833a15b1230',
			'label' => 'Access Token Secret',
			'name' => 'twitter_access_token_secret',
			'type' => 'text',
			'instructions' => '',
			'required' => 0,
			'conditional_logic' => 0,
			'wrapper' => array(
				'width' => '50',
				'class' => '',
				'id' => '',
			),
			'default_value' => '',
			'placeholder' => '',
			'prepend' => '',
			'append' => '',
			'maxlength' => '',
		),
		array(
			'key' => 'field_5a8339109311b',
			'label' => 'User ID',
			'name' => 'twitter_user_id',
			'type' => 'text',
			'instructions' => '',
			'required' => 0,
			'conditional_logic' => 0,
			'wrapper' => array(
				'width' => '',
				'class' => '',
				'id' => '',
			),
			'default_value' => '',
			'placeholder' => '',
			'prepend' => '',
			'append' => '',
			'maxlength' => '',
		),
	),
	'location' => array(
		array(
			array(
				'param' => 'options_page',
				'operator' => '==',
				'value' => 'acf-options',
			),
		),
	),
	'menu_order' => 0,
	'position' => 'normal',
	'style' => 'default',
	'label_placement' => 'top',
	'instruction_placement' => 'label',
	'hide_on_screen' => '',
	'active' => 1,
	'description' => '',
));

endif;

?>