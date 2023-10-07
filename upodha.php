<?php
/*
Plugin Name: UPODHA Plugin
Description: Integration between Ubi-House and Home Assistant for smart home management
Version: 1.0
Author: Tao Zhou
*/

// 添加后台设置页面
add_action('admin_menu', 'upodha_admin_menu');

function upodha_admin_menu() {
    add_options_page(
        'UPODHA Plugin Settings',
        'UPODHA Settings',
        'manage_options',
        'upodha-settings',
        'upodha_settings_page'
    );
}

function upodha_settings_page() {
    if (isset($_POST['fetch_entities'])) {
        fetch_homeassistant_entities();
    }

    if (isset($_POST['save_entities'])) {
        $selected_entities = $_POST['entities'] ?? [];
        update_option('upodha_selected_entities', $selected_entities);
    }

    $selected_entities = get_option('upodha_selected_entities', []);
    $all_entities = get_option('upodha_all_entities', []);
    ?>
    <div class="wrap">
        <h1>UPODHA Plugin Settings</h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('upodha_settings');
            do_settings_sections('upodha-settings');
            submit_button();
            ?>
        </form>
        <form method="post">
            <h2>Home Assistant Entities</h2>
            <p>Choose the entities you want to import:</p>
            <?php foreach ($all_entities as $entity): ?>
                <input type="checkbox" name="entities[]" value="<?php echo esc_attr($entity); ?>" <?php checked(in_array($entity, $selected_entities)); ?>> <?php echo esc_html($entity); ?><br>
            <?php endforeach; ?>
            <input type="submit" name="fetch_entities" value="Fetch Entities" class="button">
            <input type="submit" name="save_entities" value="Save Selection" class="button button-primary">
        </form>
    </div>
    <?php
}

// 注册设置项
add_action('admin_init', 'upodha_settings_init');

function upodha_settings_init() {
    register_setting('upodha_settings', 'home_assistant_url');
    register_setting('upodha_settings', 'home_assistant_api_token');

    add_settings_section(
        'upodha_settings_section',
        'UPODHA Plugin Configuration',
        'upodha_settings_section_callback',
        'upodha-settings'
    );

    add_settings_field(
        'home_assistant_url',
        'Home Assistant URL',
        'home_assistant_url_callback',
        'upodha-settings',
        'upodha_settings_section'
    );

    add_settings_field(
        'home_assistant_api_token',
        'Home Assistant API Token',
        'home_assistant_api_token_callback',
        'upodha-settings',
        'upodha_settings_section'
    );
}

function upodha_settings_section_callback() {
    echo 'Enter your Home Assistant configuration details below:';
}

function home_assistant_url_callback() {
    $home_assistant_url = get_option('home_assistant_url');
    echo "<input type='text' name='home_assistant_url' value='{$home_assistant_url}' />";
}

function home_assistant_api_token_callback() {
    $home_assistant_api_token = get_option('home_assistant_api_token');
    echo "<input type='text' name='home_assistant_api_token' value='{$home_assistant_api_token}' />";
}

// 获取HomeAssistant的实体列表并保存
function fetch_homeassistant_entities() {
    $home_assistant_url = get_option('home_assistant_url');
    $api_token = get_option('home_assistant_api_token');
    $endpoint_url = $home_assistant_url . "/api/states";

    $response = wp_remote_get($endpoint_url, [
        'headers' => [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $api_token
        ]
    ]);

    if (is_wp_error($response)) return;

    $data = wp_remote_retrieve_body($response);
    $json_data = json_decode($data, true);

    if ($json_data && is_array($json_data)) {
        $entities = [];
        foreach ($json_data as $item) {
            $entities[] = $item['entity_id'];
        }
        update_option('upodha_all_entities', $entities);
    }
}

// 定期从HomeAssistant拉取数据
function fetch_homeassistant_data() {
    $selected_entities = get_option('upodha_selected_entities', []);
    $home_assistant_url = get_option('home_assistant_url');
    $api_token = get_option('home_assistant_api_token');
    $endpoint_url = $home_assistant_url . "/api/states";

    $response = wp_remote_get($endpoint_url, [
        'headers' => [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $api_token
        ]
    ]);

    if (is_wp_error($response)) return;

    $data = wp_remote_retrieve_body($response);
    $json_data = json_decode($data, true);

    if ($json_data && is_array($json_data)) {
        foreach ($json_data as $item) {
            if (in_array($item['entity_id'], $selected_entities)) {
                wp_insert_post([
                    'post_title'   => sanitize_text_field($item['entity_id']),
                    'post_content' => wp_kses_post($item['state']),
                    'post_status'  => 'publish',
                    'post_author'  => 1,
                    'post_type'    => 'post',
                ]);
            }
        }
    }
}

if (!wp_next_scheduled('upodha_fetch_homeassistant_data_cron')) {
    wp_schedule_event(time(), 'hourly', 'upodha_fetch_homeassistant_data_cron');
}

add_action('upodha_fetch_homeassistant_data_cron', 'fetch_homeassistant_data');
