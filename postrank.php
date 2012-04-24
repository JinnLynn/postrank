<?php
/*
Plugin Name: PostRank
Plugin URI: http://jeeker.net/projects/postrank/
Description: The ranking of your posts. Visit <a href="http://jeeker.net/projects/postrank/">Jeeker</a> for usage information and project news.
Author: JinnLynn
Version: 0.1.1
Author URI: http://jeeker.net/
*/

/**
 * 插件版本
 *
 * @since 0.1
 */
define('POSTRANK_VERSION', '0.1.1');

/**
 * JPostRank
 * 
 * 日志排名处理类
 *
 * @todo 指定时间段、分类排名日志输出，自动变换
 */
class PostRank {
    /**
     * 默认配置
     *
     * @var array
     */
    private $DefaultOptions = array( 'single_value'         => 1,
                                     'comment_value'        => 3,
                                     'pingback_value'       => 5,
                                     'trackback_value'      => 8,
                                     'exclude_bots'         => true,
                                     'fresh_interval'       => 1800,
                                     'show_tips'            => true,
                                     'include_content'      => false,
                                     'widget_popular_title' => 'Most Popular Post',
                                     'widget_popular_limit' => 10,
                                     'widget_viewed_title'  => 'Most Viewed Post',
                                     'widget_viewed_limit'  => 10 );
    
    /**
     * 搜索机器人列表
     *
     * @var array
     */
    private $Bots = array( 'Google Bot'    => 'google',
                           'Baidu Bot'     => 'baidu', 
                           'MSN'           => 'msnbot',
                           'Yahoo'         => 'yahoo', 
                           'Alex'          => 'ia_archiver', 
                           'Lycos'         => 'lycos', 
                           'Ask Jeeves'    => 'jeeves', 
                           'Altavista'     => 'scooter', 
                           'AllTheWeb'     => 'fast-webcrawler', 
                           'Inktomi'       => 'slurp@inktomi', 
                           'Turnitin.com'  => 'turnitinbot', 
                           'Technorati'    => 'technorati', 
                           'Findexa'       => 'findexa', 
                           'NextLinks'     => 'findlinks', 
                           'Gais'          => 'gaisbo', 
                           'WiseNut'       => 'zyborg', 
                           'WhoisSource'   => 'surveybot', 
                           'Bloglines'     => 'bloglines',  
                           'BlogSearch'    => 'blogsearch', 
                           'PubSub'        => 'pubsub', 
                           'Syndic8'       => 'syndic8',
                           'RadioUserland' => 'userland', 
                           'Gigabot'       => 'gigabot', 
                           'Become.com'    => 'become.com');
    
    /**
     * 最高积分
     *
     * @var int
     */
    private $TopRank;
    
    /**
     * 当前配置信息
     *
     * @var array
     */
    private $Options;
    
    /**
     * 构造函数
     *
     * @since 0.1
     */
    function __construct() {
        $this->ParseOptions();
        $this->GetTopRank();
        $this->InitWidget();
        
        //register_activation_hook(__FILE__, array(&$this, 'ReStat'));
        add_action('wp_footer', array(&$this, 'RecordViews'), 99);
        add_action('shutdown', array(&$this, 'SaveOptions'));
        add_action('admin_menu', array(&$this, 'AddAdminMenu'));
        add_action('wp_update_comment_count', array(&$this, 'RecordRank'));
        add_action('delete_page', array(&$this, 'DeleteRecord'));
        add_action('delete_post', array(&$this, 'DeleteRecord'));
        add_filter('cron_schedules', array(&$this, 'AddScheduleRecurrence'));
        add_action('postrank_weekly_restat_schedule', array(&$this, 'ReStat'));
        if (!wp_next_scheduled('postrank_weekly_restat_schedule'))
            wp_schedule_event(time(), 'weekly', 'postrank_weekly_restat_schedule');
        
        if (is_admin())
            wp_enqueue_script('jquery');
    }
    
    /**
     * 获取配置
     *
     * @since 0.1
     */
    function ParseOptions() {
        $old_options = get_option('postrank_options');
        if (is_array($old_options)) {
            $this->Options = array_merge($this->DefaultOptions, $old_options);
            foreach ( $this->Options as $option_key => $option_value ) {
                if ( !array_key_exists($option_key, $this->DefaultOptions) )
                    unset($this->Options[$option_key]);
            }
        } else {
            $this->Options = $this->DefaultOptions;
        }
    }
    
    /**
     * 保存配置
     * 
     * @since 0.1
     */
    function SaveOptions() {
        update_option('postrank_options', $this->Options);
    }
    
    /**
     * 更新配置
     *
     * @since 0.1
     */
    function UpdateOptions() {
        $post_options = $_POST;
        foreach ( $post_options as $key => $value ) {
            if (!array_key_exists( $key, $this->DefaultOptions)) {
                unset($post_options[$key]);
                continue;
            }
            $post_options[$key] = stripslashes(trim($post_options[$key]));
            settype($post_options[$key], gettype($this->DefaultOptions[$key]));
        }
        $this->Options = array_merge($this->Options, $post_options);
        $this->ReStat();
    }
    
    /**
     * 重置配置
     *
     * @since  0.1
     */
    function ResetOptions() {
        $post_options = $_POST;
        foreach ( $post_options as $key => $value ) {
            if ( array_key_exists($key, $this->DefaultOptions) ) {
                $this->Options[$key] = $this->DefaultOptions[$key];
            }
        }
        $this->ReStat();
    }
    
    /**
     * 添加计划周期
     *
     * @since 0.1.1
     * @param array $schedules
     * @return array
     */
    function AddScheduleRecurrence($schedules) {
        $schedules['weekly'] = array('interval' => 604800, 'display' => __('Once Weekly'));
        return $schedules;
    }
    
    /**
     * 记录浏览数
     *
     * @since 0.1
     */
    function RecordViews() {
        if (!is_single() && !is_page()) 
            return;
        if ($this->Options['exclude_bots']) {
            $useragent = $_SERVER['HTTP_USER_AGENT'];
            foreach ($this->Options as $bot_name => $bot_agent) { 
                if (stristr($useragent, $bot_agent) !== false) 
                    return;
            }
        }
        global $post;
        $this->UpdateViews($post->ID);
    }
    
    /**
     * 记录某篇日志积分
     * 
     * @since 0.1
     * @param int $post_id
     */
    function RecordRank($post_id = 0) {
        global $wpdb;
        $post_id = intval($post_id);
        if ($post_id <= 0)
            return;
        $views_value = $this->GetViews($post_id) * $this->Options['single_value'];
        $sql = "
                SELECT comment_type
                FROM $wpdb->comments
                WHERE comment_post_ID=$post_id AND comment_approved='1'
               ";
        $results = $wpdb->get_results($sql);
        if (empty($results))
            return $this->UpdateRank($post_id, $views_value, true);
        $rank = 0;
        foreach ($results AS $comment) {
            if ($comment->comment_type == '')
                $rank += $this->Options['comment_value'];
            else if ($comment->comment_type == 'pinkback')
                $rank += $this->Options['pingback_value'];
            else if ($comment->comment_type == 'trackback')
                $rank += $this->Options['trackback_value'];
        }
        $rank += $views_value;
        $this->UpdateRank($post_id, $rank, true);
    }
    
    /**
     * 删除某篇日志记录
     *
     * @since 0.1
     * @param int $post_id
     */
    function DeleteRecord($post_id = 0) {
        delete_post_meta($post_id, '_post_views');
        delete_post_meta($post_id, '_post_rank');
    }
    
    /**
     * 获取某篇日志浏览数
     *
     * @since 0.1
     * @param int $post_id
     * @return int
     */
    function GetViews($post_id = 0) {
        return intval(get_post_meta($post_id, '_post_views', true));
    }
    
    /**
     * 更新某篇日志浏览数
     *
     * @since 0.1
     * @param int $post_id
     */
    function UpdateViews($post_id = 0) {
        $post_views = $this->GetViews($post_id) + 1;
        if (!update_post_meta($post_id, '_post_views', $post_views))
           add_post_meta($post_id, '_post_views', 1);
        $this->UpdateRank($post_id, $this->Options['single_value']);
    }
    
    /**
     * 获取某篇日志积分
     *
     * @since 0.1
     * @param int $post_id
     * @return int
     */
    function GetRank($post_id = 0) {
        return intval(get_post_meta($post_id, '_post_rank', true));
    }
    
    /**
     * 更新某篇日志积分
     *
     * @param int $post_id
     * @param int $add
     * @param bool $reset
     */
    function UpdateRank($post_id = 0, $add = 0, $reset = false) {
        ($reset) ? ($rank = $add) : ($rank = $this->GetRank($post_id) + $add);
        if (!update_post_meta($post_id, '_post_rank', $rank))
            add_post_meta($post_id, '_post_rank', $rank);        
    }
    
   /**
     * 重新统计
     *
     * @since 0.1
     */
    function ReStat() {
        $posts = array();
        global $wpdb;
        $sql = "
                SELECT comment_post_ID AS post_id, COUNT(comment_ID) AS comment_nums
                FROM $wpdb->comments
                WHERE comment_approved=1 AND comment_type=''
                GROUP BY comment_post_ID
               ";
        $results = $wpdb->get_results($sql);
        foreach ($results as $post) {
            $posts[intval($post->post_id)]['comment'] = intval($post->comment_nums);
        }
        $sql = "
                SELECT comment_post_ID AS post_id, COUNT(comment_ID) AS pingback_nums
                FROM $wpdb->comments
                WHERE comment_approved=1 AND comment_type='pingback'
                GROUP BY comment_post_ID
               ";
        $results = $wpdb->get_results($sql);
        foreach ($results as $post) {
            $posts[intval($post->post_id)]['pingback'] = intval($post->pingback_nums);
        }
        $sql = "
                SELECT comment_post_ID AS post_id, COUNT(comment_ID) AS trackback_nums
                FROM $wpdb->comments
                WHERE comment_approved=1 AND comment_type='trackback'
                GROUP BY comment_post_ID
               ";
        $results = $wpdb->get_results($sql);
        foreach ($results as $post) {
            $posts[intval($post->post_id)]['trackback'] = intval($post->trackback_nums);
        }
        $sql = "
                SELECT post_id, meta_value AS post_views
                FROM $wpdb->postmeta
                WHERE meta_key='_post_views'
               ";
        $results = $wpdb->get_results($sql);
        foreach ($results as $post) {
            $posts[intval($post->post_id)]['views'] = intval($post->post_views);              
        }
        foreach ($posts as $post_id => $ranks) {
            if ($post_id <= 0)
               break;
            $score = $ranks['comment'] * $this->Options['comment_value']
                     + $ranks['pingback'] * $this->Options['pingback_value']
                     + $ranks['trackback'] * $this->Options['trackback_value']
                     + $ranks['views'] * $this->Options['single_value'];
            $this->UpdateRank($post_id, $score, true);
        }
        $this->GetTopRank();
    }
    
    /**
     * 获取所有日志中的最高积分，其他日志的Rank值依此计算
     *
     * @since 0.1
     */
    function GetTopRank() {
        global $wpdb;
        $sql = "
                SELECT (meta_value+0) AS rank
                FROM $wpdb->postmeta
                WHERE meta_key='_post_rank'
                ORDER BY rank DESC
                LIMIT 1
               ";
        $this->TopRank = $wpdb->get_var($sql);
    }
    
    /**
     * 显示日志排名
     *
     * @since 0.1
     * @param int $post_id
     */
    function ShowPostRank($post_id = 0) {
        $rank = $this->GetPostRank($post_id);
        if ($this->Options['show_tips'])
            $help = ' title="Top Score: 10.0 "';
        $output = '<span class="postrank"' . $help . '>Rank: ' . $rank . '</span>';
        echo $output;
    }
    
    /**
     * 获取日志排名
     *
     * @since 0.1
     * @param int $post_id
     * @return str
     */
    function GetPostRank($post_id) {
        if ($this->TopRank <= 0)
            return 0;
        return sprintf('%0.2f', $this->GetRank($post_id) / $this->TopRank * 10);
    }
    
    /**
     * 显示最高积分列表
     *
     * @since 0.1
     * @param str | obj | array $args
     */
    function ShowTopRanked($args = '') {
        echo $this->GetTopRanked($args);
    }
    
    /**
     * 获取最高积分列表
     *
     * @since 0.1
     * @param str | obj | array $args
     * @return str $output
     */
    function GetTopRanked($args = '') {
        global $wpdb;
        $defaults = array( 'mode'   => '',                 //TIPS: 日志:$mode='post | 页面:$mode='page' | 全部:$mode=''
                           'limit'  => 10,
                           'before' => '<li>',
                           'after'  => '</li>' );
        extract(wp_parse_args($args, $defaults));
        ($mode=='post' || $mode=='page') ? ($where = "AND $wpdb->posts.post_type='$mode'") : ($where = "");
        $current_time = current_time('mysql');
        $sql = "
                SELECT $wpdb->posts.ID, $wpdb->posts.post_title, ($wpdb->postmeta.meta_value+0) AS rank
                FROM $wpdb->posts
                LEFT JOIN $wpdb->postmeta
                ON $wpdb->postmeta.post_id = $wpdb->posts.ID
                WHERE $wpdb->posts.post_date < '$current_time' AND $wpdb->posts.post_status='publish' AND $wpdb->postmeta.meta_key='_post_rank' $where
                ORDER BY rank DESC, $wpdb->posts.post_date DESC
                LIMIT $limit
                ";
        $results = $wpdb->get_results($sql);
        foreach ($results as $post) {
            $output .= "\t" . $before . '<a href="' . get_permalink($post->ID) . '" title="' . htmlspecialchars($post->post_title, ENT_QUOTES) . '">' . htmlspecialchars($post->post_title, ENT_QUOTES) . '</a>' . $after . "\n";
        }
        if (empty($output)) 
            $output .= $before . 'none...' . $after;
        $output = "\t" . '<!-- Generated by PostRank ' . POSTRANK_VERSION . ' - http://jeeker.net/projects/postrank/ -->' . "\n" . $output;
        return $output;
    }

    /**
     * 显示日志浏览数
     *
     * @since 0.1
     * @param str | obj | array $args
     */
    function ShowPostViews($args = '') {
        echo $this->GetPostViews($args);
    }
    /**
     * 获取日志浏览数
     *
     * @since 0.1
     * @param str | obj | array $args
     * @return int
     */
    function GetPostViews($args = '') {
        global $post;
        $defaults = array( 'zero'    => 'No Views',
                           'one'     => '1 View',
                           'more'    => '% Views',
                           'post_id' => $post->ID );
        extract(wp_parse_args($args, $defaults));
        $views_num = $this->GetViews($post_id);
        if ($views_num == 0)
            $output = $zero;
        else if ($views_num == 1)
            $output = $one;
        else
            $output = str_replace('%', number_format_i18n($number), $more);
        return $output;       
    }
    
    /**
     * 显示浏览数最多的日志
     *
     * @since 0.1
     * @param str | obj | array $args
     */
    function ShowMostViewed($args = '') {
        echo $this->GetMostViewed($args);
    }
    
    /**
     * 获取浏览数最多的日志
     * 
     * @since 0.1
     * @param str | obj | array $args
     * @return str $output
     */
    function GetMostViewed($args = '') {
       global $wpdb;
       $defaults = array( 'mode'   => '',                 //TIPS: 日志:$mode='post | 页面:$mode='page' | 全部:$mode=''
                          'limit'  => 10,
                          'before' => '<li>',
                          'after'  => '</li>' );
       extract(wp_parse_args($args, $defaults));
       ($mode=='post' || $mode=='page') ? ($where = "AND $wpdb->posts.post_type='$mode'") : ($where = "");
       $current_time = current_time('mysql');
       $sql = "
               SELECT $wpdb->posts.ID, $wpdb->posts.post_title, ($wpdb->postmeta.meta_value + 0) AS views 
               FROM $wpdb->posts 
               LEFT JOIN $wpdb->postmeta 
               ON $wpdb->postmeta.post_id = $wpdb->posts.ID 
               WHERE $wpdb->posts.post_date < '$current_time' AND $wpdb->posts.post_status = 'publish' AND $wpdb->postmeta.meta_key = '_post_views'  $where
               ORDER BY views DESC, $wpdb->posts.post_date DESC
               LIMIT $limit
              ";
       $results = $wpdb->get_results($sql);
       foreach ($results as $post) {
           $output .= "\t" . $before . '<a href="' . get_permalink($post->ID) . '" title="' . htmlspecialchars($post->post_title, ENT_QUOTES) . '">' . htmlspecialchars($post->post_title, ENT_QUOTES) . '</a>' . $after  . "\n";
       }
       if (empty($output)) 
           $output = $before . 'none...' . $after;
       $output = "\t" . '<!-- Generated by PostRank ' . POSTRANK_VERSION . ' - http://jeeker.net/projects/postrank/ -->' . "\n" . $output;
       return $output;   
    }

    /**
     * 获取全部浏览数
     *
     * @since 0.1
     * @param bool $echo
     * @return null | int
     */
    function GetTotalViews($echo = true) {
        global $wpdb;
        $sql = "
                SELECT SUM(meta_value+0)
                FROM $wpdb->postmeta
                WHERE meta_key = '_post_views'
               ";
        $total_views = $wpdb->get_var($sql);
        if (!$echo)
            return $total_views;
        echo $total_views;
    }
    
    /**
     * 初始化Widget
     *
     * @since 0.1
     */
    function InitWidget() {
        $widget_ops = array('classname' => 'widget_popular_postrank', 'description' => __( 'Most Popular Post') );
        wp_register_sidebar_widget('postrank_popular', __('PR: Popular'), array($this, 'ShowPopularWidget'), $widget_ops);
        wp_register_widget_control('postrank_popular', __('PR: Popular'), array($this, 'ControlPopularWidget'));
        $widget_ops = array('classname' => 'widget_viewed_postrank', 'description' => __( 'Most Viewed Post') );
        wp_register_sidebar_widget('postrank_viewed', __('PR: Viewed'), array($this, 'ShowViewedWidget'), $widget_ops);
        wp_register_widget_control('postrank_viewed', __('PR: Viewed'), array($this, 'ControlViewedWidget'));
    }
    
    /**
     * 显示最受欢迎日志Widget
     *
     * @since 0.1
     * @param array $widget_args
     * @param int $number
     */
    function ShowPopularWidget($widget_args) {
        extract($widget_args);
        $title = $this->Options['widget_popular_title'];
        $limit = $this->Options['widget_popular_limit'];
        $args = array('limit' => $limit);
        echo $before_widget;
        echo $before_title . $title . $after_title;
        echo "\n" . '<ul>' . "\n";
        echo $this->GetTopRanked($args);
        echo '</ul>' . "\n";
        echo $after_widget;
    }
    
    /**
     * 设置最受欢迎日志Widget选项
     *
     * @since 0.1
     */
    function ControlPopularWidget() {
        if($_POST['postrank_popular_widget_submit'] == 1) {
            $this->Options['widget_popular_title'] = strip_tags(stripslashes($_POST['postrank_popular_widget_title']));
            $this->Options['widget_popular_limit'] = intval(strip_tags(stripslashes($_POST['postrank_popular_widget_limit'])));
        }
        echo '
            <p><label for="postrank_popular_widget_title">Title: <input class="widefat" style="width:200px;" id="postrank_popular_widget_title" name="postrank_popular_widget_title" type="text" value="' . $this->Options['widget_popular_title'] . '" /></label></p>
            <p><label for="postrank_popular_widget_limit">Number: <input class="widefat" style="width:35px;" id="postrank_popular_widget_limit" name="postrank_popular_widget_limit" type="text" value="' . $this->Options['widget_popular_limit'] . '" /></label></p>
            <input type="hidden" id="postrank_popular_widget_submit" name="postrank_popular_widget_submit" value="1" />
        '; 
    }
    
    /**
     * 显示最多浏览数日志Widget
     *
     * @since 0.1
     * @param array $widget_args
     * @param int $number
     */
    function ShowViewedWidget($widget_args, $number = 1) {
        extract($widget_args);
        $title = $this->Options['widget_viewed_title'];
        $limit = $this->Options['widget_viewed_limit'];
        $args = array('limit' => $limit);
        echo $before_widget;
        echo $before_title . $title . $after_title;
        echo "\n" . '<ul>' . "\n";
        echo $this->GetMostViewed($args);
        echo '</ul>' . "\n";
        echo $after_widget;
    }
    
    /**
     * 设置最多浏览数日志Widget选项
     *
     * @since 0.1
     */
    function ControlViewedWidget() {
        if($_POST['postrank_viewed_widget_submit'] == 1) {
            $this->Options['widget_viewed_title'] = strip_tags(stripslashes($_POST['postrank_viewed_widget_title']));
            $this->Options['widget_viewed_limit'] = intval(strip_tags(stripslashes($_POST['postrank_viewed_widget_limit'])));
        }
        echo '
            <p><label for="postrank_viewed_widget_title">Title: <input class="widefat" style="width:200px;" id="postrank_viewed_widget_title" name="postrank_viewed_widget_title" type="text" value="' . $this->Options['widget_viewed_title'] . '" /></label></p>
            <p><label for="postrank_viewed_widget_limit">Number: <input class="widefat" style="width:35px;" id="postrank_viewed_widget_limit" name="postrank_viewed_widget_limit" type="text" value="' . $this->Options['widget_viewed_limit'] . '" /></label></p>
            <input type="hidden" id="postrank_viewed_widget_submit" name="postrank_viewed_widget_submit" value="1" />
        ';        
    }
    
    /**
     * 根据过滤获取相关日志排名
     *
     * @since 0.1
     * @param int $per_page
     * @return obj 
     */
    function QueryReport($per_page = 15) {
        global $wpdb;    
        extract($_GET);
        if ($paged <= 0)
            $paged = 1;
        $select = "$wpdb->posts.ID AS id, $wpdb->posts.post_author AS author_id, $wpdb->posts.post_title AS title, $wpdb->posts.post_date AS post_date";
        $join = '';
        $where = "$wpdb->posts.post_status = 'publish'";
        $order = '';
        $limit = ($paged - 1) * $per_page . "," . $per_page;
        if ($pr_type != 'post' && $pr_type != 'page')
            $where .= " AND ($wpdb->posts.post_type = 'post' OR $wpdb->posts.post_type = 'page')";
        else
            $where .= " AND $wpdb->posts.post_type = '$pr_type'";
            
        $pr_date = '' . preg_replace('|[^0-9]|', '', $pr_date);
        if (strlen($pr_date) == 6) {
            $year = substr($pr_date, 0, 4);
            $month = substr($pr_date, 4, 2);
            $where .= " AND YEAR($wpdb->posts.post_date) = $year AND MONTH($wpdb->posts.post_date) = $month";
        }
        
        if (intval($pr_cate) > 0) {
            $join .= "
                      INNER JOIN $wpdb->term_relationships 
                      ON ($wpdb->posts.ID = $wpdb->term_relationships.object_id) 
                      INNER JOIN $wpdb->term_taxonomy 
                      ON ($wpdb->term_relationships.term_taxonomy_id = $wpdb->term_taxonomy.term_taxonomy_id)
                     ";
            $where .= " AND $wpdb->term_taxonomy.taxonomy = 'category' AND $wpdb->term_taxonomy.term_id = $pr_cate";
        }
        
        if (intval($pr_author) > 0) {
            $join .= "
                      INNER JOIN $wpdb->users
                      ON ($wpdb->posts.post_author = $wpdb->users.ID)
                     ";
            $where .= " AND $wpdb->posts.post_author = $pr_author";
        }
        
        if ($pr_order != 'date' && $pr_order != 'views') {
            $select .= ", ($wpdb->postmeta.meta_value+0) AS rank";
            $meta_key = '_post_rank';
            $order .= "rank DESC,"; 
        } else if ($pr_order == 'views'){
            $select .= ", ($wpdb->postmeta.meta_value+0) AS views"; 
            $meta_key = '_post_views';
            $order .= "views DESC,"; 
        }
        
        $sql = "
                SELECT SQL_CALC_FOUND_ROWS $select
                FROM $wpdb->posts
                LEFT JOIN $wpdb->postmeta
                ON ($wpdb->posts.ID = $wpdb->postmeta.post_id AND $wpdb->postmeta.meta_key = '$meta_key')
                $join
                WHERE $where
                ORDER BY $order $wpdb->posts.post_date DESC
                LIMIT $limit
               ";
        return $wpdb->get_results($sql);
    }
    
    /**
     * 显示报告
     *
     * @since 0.1
     */
    function Report() {
        global $wpdb, $wp_locale;
        $per_page = 15;
        extract($_GET);
        if ($paged <= 0)
            $paged = 1;          
        $posts = $this->QueryReport($per_page);
        $post_nums = $wpdb->get_var("SELECT FOUND_ROWS()");
        $max_page = ceil($post_nums / $per_page);
        echo '
        <h2>PostRank Reports</h2>
        <form id="postrank-report" action="" method="get">   
        <input type="hidden" name="postrank_report_action" value="1" />
        <input type="hidden" name="page" value="postrank_admin_options_page" />
        <div class="tablenav">
            <div class="alignleft actions">
                <select name="pr_type">
                    <option value=""' . (($pr_type!='post' && $pr_type!='page') ? ' selected="selected"' : '') . '>All Type</option>
                    <option value="post"' . ($pr_type == 'post' ? ' selected="selected"' : '') . '>Post Only</option>
                    <option value="page"' . ($pr_type == 'page' ? ' selected="selected"' : '') . '>Page Only</option>
                </select>
        ';
        $sql = "
                SELECT DISTINCT YEAR(post_date) AS year, MONTH(post_date) AS month 
                FROM $wpdb->posts 
                WHERE (post_type='post' OR post_type='page') AND post_status='publish'
                ORDER BY post_date DESC
               ";
        $date_results = $wpdb->get_results($sql);
        $month_count = count($date_results);
        if ( $month_count && !( 1 == $month_count && 0 == $date_results[0]->month ) ) {
            $pr_date = intval($pr_date);
            echo '
                <select name="pr_date">
                    <option value="0"' . ($pr_date == 0 ? ' selected="selected"' : '') . '>All Dates</option>
            ';
            foreach ($date_results as $date) {
                if ($date->year == 0)
                    continue;
                $date->month = zeroise($date->month, 2);
                $yearmonth = $date->year . $date->month;
                echo '
                    <option value="' . $yearmonth . '"' . ($yearmonth == $pr_date ? ' selected="selected"' : '') . '>' . $wp_locale->get_month($date->month) . ' ' . $date->year . '</option>
                ';
            }
            echo '
                </select>
            ';
        }
        $dropdown_options = array( 'show_option_all' => 'All Categories', 'hide_empty' => 0, 'hierarchical' => 1, 'name' => 'pr_cate',
                                   'show_count' => 0, 'orderby' => 'name', 'selected' => $pr_cate);
        wp_dropdown_categories($dropdown_options);
        
        $sql = "
                SELECT ID, display_name
                FROM $wpdb->users
               ";
        $authors = $wpdb->get_results($sql);
        echo '
                <select name="pr_author">
                    <option value=""' . ($pr_author==0 ? ' selected="selected"' : '') . '>All Author</option>
             ';
        foreach ($authors as $author) {
            echo '
                    <option value="' . $author->ID . '"' . ($pr_author==$author->ID ? ' selected="selected"' : '') . '>' . $author->display_name . '</option>
            ';
            
        }
        echo '
                </select>
                <select name="pr_order">
                    <option value="rank"' . ($pr_order != 'views' ? ' selected="selected"' : '') . '>Order By Rank</option>
                    <option value="views"' . ($pr_order == 'views' ? ' selected="selected"' : '') . '>Order By Views</option>
                    <option value="date"' . ($pr_order == 'date' ? ' selected="selected"' : '') . '>Order By Date</option>
                </select>
        '; 


        echo '
                <input type="submit" id="postrank-query-submit" value="Filter" class="button-secondary" />
            </div>
        ';
        $page_links = paginate_links( array(
                                            'base' => add_query_arg( 'paged', '%#%' ),
                                            'format' => '',
                                            'prev_text' => __('&laquo;'),
                                            'next_text' => __('&raquo;'),
                                            'total' => $max_page,
                                            'current' => $paged
                                     ));
        if ( $page_links ) {
            $page_links_text = sprintf( '<span class="displaying-num">' . __( 'Displaying %s&#8211;%s of %s' ) . '</span>%s',
                                        number_format_i18n( ( $paged - 1 ) * $per_page + 1 ),
                                        number_format_i18n( min( $paged * $per_page, $post_nums ) ),
                                        number_format_i18n( $post_nums ),
                                        $page_links
                                       );
            echo '
                <div class="tablenav-pages">' .$page_links_text . '</div>
            ';
        }
        echo '</div>    
        <table class="widefat" cellspacing="0">
            <thead>
                <tr>
                    <th scope="col"manage-column column-title>Post</th>
                    <th scope="col" class="num" id="order-rank">Rank</th>
                    <th scope="col" class="num" id="order-views">Views</th>
                    <th scope="col" class="manage-column column-author">Author</th>
                    <th scope="col" class="manage-column column-categories">Categories</th>
                    <th scope="col"manage-column column-date id="order-date">Date</th>
                </tr>
            </thead>
    
            <tfoot>
                <tr>
                    <th scope="col"manage-column column-title>Post</th>
                    <th scope="col" class="num" id="order-rank">Rank</th>
                    <th scope="col" class="num" id="order-views">Views</th>
                    <th scope="col" class="manage-column column-author">Author</th>
                    <th scope="col" class="manage-column column-categories">Categories</th>
                    <th scope="col"manage-column column-date id="order-date">Date</th>
                </tr>
            </tfoot>
    
            <tbody class="plugins">
        ';
        if (count($posts) == 0) {
            echo '
                <tr>
                    <td cols="6" class="post-title column-title"><strong>Sorry, no posts matched your criteria.</strong></td>
                </tr>
            ';
        }
        foreach ($posts as $post) {
            $cates = get_the_category($post->id);
            foreach ($cates as $cate) {
                if (!empty($cate_output))
                    $cate_output .= ', ';
                $cate_output .= '<a href="#' . $cate->cat_ID . '">' . $cate->cat_name . '</a>';
            }
            echo '
                <tr>
                    <td class="post-title column-title"><strong><a class="row-title" href="' . get_permalink($post->id) . '" title="' . htmlspecialchars($post->title, ENT_QUOTES) . '">' . htmlspecialchars($post->title, ENT_QUOTES) . '</a></strong></td>
                    <td class="num">' . $this->GetPostRank($post->id) . '</td>
                    <td class="num">' . $this->GetViews($post->id) . '</td>
                    <td class="author column-author"><a href="#' . $post->author_id . '">' . get_author_name($post->author_id) . '</a></td>
                    <td class="categories column-categories">' . $cate_output . '</td>
                    <td class="date column-date">' . $post->post_date . '</td>
                </tr>
                ';
            $cate_output = '';
        }
        echo '
            </tbody>
        </table>
        <div class="tablenav">
                <div class="tablenav-pages">' .$page_links_text . '</div>
        </div>
        </form>
        ';
        
    }
    
    /**
     * 添加管理菜单
     *
     * @since 0.1
     */
    function AddAdminMenu() {
        add_options_page('PostRank', 'PostRank', 'manage_options', 'postrank_admin_options_page', array(&$this, 'AdminPage'));
    }
    
    /**
     * 后台管理页面
     *
     * @since 0.1
     */
    function AdminPage() {
        if (isset($_POST['update_options']))
            $this->UpdateOptions();
        else if (isset($_POST['reset_options']))
            $this->ResetOptions();
        else if (isset($_POST['re_stat']))
            $this->ReStat();

        echo '
<script type="text/javascript">
    jQuery(function($) {
        $("td.author > a, td.categories > a").click(function() {
            var hash = $(this).attr("href").slice(1);
            if($(this).parent().hasClass("author")) {
               QuerySubmit("pr_author", hash);
            } else if ($(this).parent().hasClass("categories")) {
               QuerySubmit("pr_cate", hash);
            }
            return false;
        });
        
        $("#order-rank, #order-views, #order-date").hover(
            function() {
                $(this).css("cursor","pointer");
            }, function() {
                $(this).css("cursor","default");
            }
        );
        
        $("#order-rank, #order-views, #order-date").click(function() {
            QuerySubmit("pr_order", $(this).attr("id").slice(6));
        });
            
        function QuerySubmit(selectname, selectval) {
            $("select[name=\'" + selectname + "\']").val(selectval);
            $("#postrank-report").submit();
        }
    });
</script>
    <div class="wrap">
        <h2>PostRank Options <span style="font:bold 10px verdana;">v' . POSTRANK_VERSION . '</span></h2>
        <p>Settings for the PostRank plugin. Visit <a href="http://jeeker.net/projects/postrank/">Jeeker</a> for usage information and project news.</p>
        <form id="postrank" name="postrank" method="post">
        <table class="form-table">
            <tr valign="top">
                <th scope="row">Values : </th>
                    <td>
                        Single views: <input type="text" name="single_value" id="single_value" value="' . $this->Options['single_value'] . '" size="5" /> Default: ' . $this->DefaultOptions['single_value'] . '<br />
                        Comments:&nbsp;&nbsp; <input type="text" name="comment_value" id="comment_value" value="' . $this->Options['comment_value'] . '" size="5" /> Default: ' . $this->DefaultOptions['comment_value'] . '<br />
                        Pingbacks:&nbsp;&nbsp;&nbsp; <input type="text" name="pingback_value" id="comment_value" value="' . $this->Options['pingback_value'] . '" size="5" /> Default: ' . $this->DefaultOptions['pingback_value'] . '<br />
                        Trackbacks:&nbsp; <input type="text" name="trackback_value" id="trackback_value" value="' . $this->Options['trackback_value'] . '" size="5" /> Default: ' . $this->DefaultOptions['trackback_value'] . '<br />
                    </td>
            </tr>
            <tr valign="top">
                <th scope="row">Show Tips : </th>
                <td>
                    <select name="show_tips" size="1">
                        <option value="1"' . ($this->Options['show_tips'] ? ' selected="selected"' : '') . '>YES</option>
                        <option value="0"' . (!$this->Options['show_tips'] ? ' selected="selected"' : '') . '>NO</option>
                    </select>
                </td>
            </tr>
            <tr valign="top">
                <th scope="row">Exclude Bot Views : </th>
                <td>
                    <select name="exclude_bots" size="1">
                        <option value="1"' . ($this->Options['exclude_bots'] ? ' selected="selected"' : '') . '>YES</option>
                        <option value="0"' . (!$this->Options['exclude_bots'] ? ' selected="selected"' : '') . '>NO</option>
                    </select>
                </td>
            </tr>                              
       </table>
       <p><input class="button" type="submit" name="update_options" value="Update Options &raquo;" />
          <input class="button" type="submit" name="reset_options" value="Reset Options &raquo;" />
          <input class="button" type="submit" name="re_stat" value="Restat &raquo;" /></p>
        </form>
        ';
        $this->Report();
        echo '
    </div>
        ';        
    }
}

/**
 * $JKit_Rank对象
 * 
 * @since 0.1
 * @var object
 */
global $PostRank;

/**
 * 激活插件时重新统计
 *
 * @since 0.1
 * @todo 为什么不能在类内部实现？
 */
function JPR_Activate() {
    $active = new PostRank;
    $active->ReStat();
}
register_activation_hook(__FILE__, 'JPR_Activate');

/**
 * 初始化对象
 *
 * @since 0.1
 */
function JPR_Init() {
    global $PostRank;
    $PostRank = new PostRank;
}
add_action('plugins_loaded', 'JPR_Init');
/**
 * 获取日志排名
 *
 * @param int $post_id
 * @return str
 */
function JPR_GetRank($post_id) {
    global $PostRank;
    return $PostRank->GetPostRank($post_id);
}
/**
 * 显示日志排名值
 *
 * @since 0.1
 */
function JPR_TheRank() {
    global $PostRank, $post;
    $PostRank->ShowPostRank($post->ID);
}

/**
 * 显示最受欢迎日志
 *
 * @since 0.1
 * @param str | obj | array $args
 * @return null
 */
function JPR_MostPopular($args = '') {
    global $PostRank;
    $PostRank->ShowTopRanked($args);
}

/**
 * 获取日志浏览数
 *
 * @param int $post_id
 * @return int
 */
function JPR_GetViews($post_id) {
    global $PostRank;
    return $PostRank->GetViews($post_id);
}

/**
 * 显示浏览数
 *
 * @since 0.1
 * @param @param str | obj | array $args
 */
function JPR_TheViews($args = '') {
    global $PostRank;
    $PostRank->ShowPostViews($args);
}

/**
 * 显示浏览数最多的日志
 * 
 * @since 0.1
 * @param str | obj | array $args
 */
function JPR_MostViewed($args = '') {
    global $PostRank;
    $PostRank->ShowMostViewed($args);
}
?>