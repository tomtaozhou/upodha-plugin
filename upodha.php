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
    register_setting('upodha_settings', 'upodha_cron_interval_minutes');

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

    add_settings_field(
        'upodha_cron_interval_minutes',
        'Data Fetch Interval (in minutes)',
        'upodha_cron_interval_minutes_callback',
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

function upodha_cron_interval_minutes_callback() {
    $current_value = get_option('upodha_cron_interval_minutes', '60');
    echo "<input type='number' min='1' name='upodha_cron_interval_minutes' value='{$current_value}' />";
}

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

    if (is_wp_error($response)) {
        return;
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if (is_array($data)) {
        $entities = array_column($data, 'entity_id');
        update_option('upodha_all_entities', $entities);
    }
}

function fetch_homeassistant_data() {
    $selected_entities = get_option('upodha_selected_entities', []);
    if (empty($selected_entities)) {
        return;
    }

    $home_assistant_url = get_option('home_assistant_url');
    $api_token = get_option('home_assistant_api_token');

    foreach ($selected_entities as $entity_id) {
        $endpoint_url = $home_assistant_url . "/api/states/" . $entity_id;

        $response = wp_remote_get($endpoint_url, [
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $api_token
            ]
        ]);

        if (is_wp_error($response)) {
            continue;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (isset($data['state'])) {
            wp_insert_post([
                'post_title'   => $entity_id,
                'post_content' => $data['state'],
                'post_status'  => 'publish'
            ]);
        }
    }
}

// 添加自定义的cron间隔
add_filter('cron_schedules', 'upodha_add_cron_interval');

function upodha_add_cron_interval($schedules) {
    $interval_minutes = get_option('upodha_cron_interval_minutes', '60');
    $schedules['upodha_custom_interval'] = [
        'interval' => $interval_minutes * 60,
        'display'  => 'UPODHA Custom Interval',
    ];
    return $schedules;
}

// 更新cron的时间表
add_action('update_option_upodha_cron_interval_minutes', 'update_upodha_cron_schedule', 10, 2);

function update_upodha_cron_schedule($old_value, $new_value) {
    $timestamp = wp_next_scheduled('upodha_fetch_homeassistant_data_cron');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'upodha_fetch_homeassistant_data_cron');
    }
    wp_schedule_event(time(), 'upodha_custom_interval', 'upod
