<?php
require_once trailingslashit(plugin_dir_path( __FILE__ ) . 'includes' ) . 'common.php' ;
require_once trailingslashit(plugin_dir_path(__FILE__) . 'lib') . 'classTTConnection.php';

class Tapatalk_Push{
    public static function push_post($post_id){
        $old_post = get_post();
        $post = get_post($post_id);
        if (empty($post) || empty($old_post) || $old_post->ID != $post->ID) return;
        if ('0000-00-00 00:00:00' != $old_post->post_date_gmt) return;// the post has published.

        $url = site_url();
        $response_category = array();
        $categories = wp_get_post_categories($post->ID, array('fields' => 'all_with_object_id'));
        foreach($categories as $category)
        {
            $response_category[] = array(
                'cat_id'    => $category->term_id,
                'name'      => $category->name,
            );
        }

        // get the first image associated with the post
        $preview_image = '';
        $preview_image_thum = '';
        $args_a = array(
            'numberposts' => 1,
            'order'=> 'ASC',
            'post_mime_type' => 'image',
            'post_parent' => $post->ID,
            'post_type' => 'attachment'
        );
        $attachments = get_posts($args_a);
        if ($attachments)
        {
            $first_image = $attachments[0];

            $preview_image_src = wp_get_attachment_image_src($first_image->ID, 'full');
            $preview_image_thum_src = wp_get_attachment_image_src($first_image->ID, 'thumbnail');

            if (is_array($preview_image_src) && !empty($preview_image_src)){
                $preview_image = $preview_image_src[0];
            }
            if (is_array($preview_image_thum_src) && !empty($preview_image_thum_src)){
                $preview_image_thum = $preview_image_thum_src[0];
            }
        }

        $author = get_userdata($post->post_author);
        $tapatalk_general = get_option('tapatalk_general');

        $ttp_data = array(
            'url'       => $url,
            'key'       => isset($tapatalk_general['api_key']) ? $tapatalk_general['api_key'] : '',
            'type'      => 'blog',
            'id'        => $post->ID,
            'title'     => tt_post_html_clean($post->post_title),
            'author'    => tt_post_html_clean($author->nickname),
            //'authorid'  => $author->ID,
            'category'  => serialize($response_category),
            'preview_image' => $preview_image,
            'preview_image_thum' => $preview_image_thum,
            'dateline'  => strtotime($post->post_date_gmt),
            'password'  => post_password_required($post->ID),
            'content'   => '',
            'userid'    => '',
        );

        if (!$ttp_data['password']){
            setup_postdata($post);
            $post = get_post();
            $content_ori = get_the_content(false);
            $content_ori = tt_process_content($content_ori, true);
            $content = apply_filters( 'the_content', $content_ori );
            $content = tt_process_html_content($content);
            $content = str_replace( ']]>', ']]&gt;', $content );
            $content = tt_post_html_clean($content);
            $content = strip_tags($content);
            $ttp_data['content'] = tt_process_short_content($content);
        }

        $return_status = self::_tt_do_post_request($ttp_data);
    }

    private static function _tt_do_post_request($data)
    {
        $connection = new classTTConnection();
        $push_url = 'http://push.tapatalk.com/push.php';

        if (get_option('tapatalk_push_slug') === false){
            update_option('tapatalk_push_slug', 0);
        }

        $push_slug = get_option('tapatalk_push_slug');
        $slug = base64_decode($push_slug);
        $slug = $connection->pushSlug($slug, 'CHECK');
        $check_res = unserialize($slug);
        //If it is valide(result = true) and it is not sticked, we try to send push
        if($check_res[2] && !$check_res[5])
        {
            //Slug is initialed or just be cleared
            if($check_res[8])
            {
                update_option('tapatalk_push_slug', base64_encode($slug));
            }

            //Send push
            $connection->timeout = 0;
            $push_resp = $connection->getContentFromSever($push_url, $data, 'POST');
            if(trim($push_resp) === 'Invalid push notification key') $push_resp = 1;
            if(!is_numeric($push_resp))
            {
                //Sending push failed, try to update push_slug to db
                $slug = $connection->pushSlug($slug, 'UPDATE');
                $update_res = unserialize($slug);
                if($update_res[2] && $update_res[8])
                {
                    update_option('tapatalk_push_slug', base64_encode($slug));
                }
            }
        }
    }
}