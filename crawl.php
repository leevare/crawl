<?php 
/**
 * 检查URL有效性 
 */
function check_url_valid($url) {
    $urlinfo = parse_url($url);
    if(!$urlinfo['scheme'] || !$urlinfo['host']) {
        echo "Error:链接{$url}无效<br>";
    }
    return $urlinfo;
}

/**
 * 得到页面链接
 * 当传递了data值时则不再重新抓取url页面
 * @param  string $url  url链接
 * @param  string $data 页面数据
 * @return array        链接数组
 */
function get_links($url, $data) {
	$urlinfo = check_url_valid($url);
    $baseurl = $urlinfo['scheme'].'://'.$urlinfo['host'];
    if(empty($data)) $data = get_url($url);
    return array(
        "link"=>__get_links($data, $baseurl, 'link'),
        "css"=>__get_links($data, $baseurl, 'css'),
        "script"=>__get_links($data, $baseurl, 'script'),
        "image"=>__get_links($data, $baseurl, 'image')
    );
}

/**
 * 获取链接
 * @param  string $data     页面数据
 * @param  string $baseurl  根url地址
 * @param  string $linktype 链接类型
 * @return array            链接数组
 */
function __get_links($data, $baseurl, $linktype) {
    $link_pattern = "/<a.*?href=['\"](.+?)['\"].*?<\/a>/i";
    $img_pattern = "/<img.*?src=['\"](.+?)['\"]/i";
    $script_pattern = "/<script.*?src=['\"](.+?)['\"].*?<\/script>/i";
    $css_pattern = "/<link.*?href=['\"](.+?)['\"]/i";
    $innerUrls = array();   #内链
    $outerUrls = array();   #外链
    switch ($linktype) {
        case "image":
            preg_match_all($img_pattern, $data, $matches);
            break;
        case "script":
            preg_match_all($script_pattern, $data, $matches);
            break;
        case "link":
            preg_match_all($link_pattern, $data, $matches);
            break;
        case "css":
            preg_match_all($css_pattern, $data, $matches);
            break;
    }
    $links = @$matches[1];
    if($links) {
        foreach ($links as $link) {
            @ob_flush();
            @flush();
            $real_url = format_url($link, $baseurl);
            $real_url = trim_link($real_url);
            if($real_url) {
                if(check_chain($real_url, $baseurl)) {
                    array_push($innerUrls, $real_url);
                }else {
                    array_push($outerUrls, $real_url);
                }
            }
        }
        return array(
            "inner"=>array_unique($innerUrls),
            "outer"=>array_unique($outerUrls)
        );
    }
}

/**
 * url路径处理
 * 相对路径转化为绝对路径
 * 相同url链接判断
 * @param  string $srcurl  源url地址
 * @param  string $baseurl 根url地址
 * @return string          url绝对路径
 */
function format_url($srcurl, $baseurl) {
    $srcinfo = parse_url($srcurl);
    if(isset($srcinfo['scheme'])) {
        $filename = basename(@$srcinfo['path'], '.index.html');
        if(!$filename) {
            if(substr($srcurl, -1, 1) !== '/') {
                $srcurl .= '/';
            }
        }
        return $srcurl;
    }
    $baseinfo = parse_url($baseurl);
    $url = $baseinfo['scheme'].'://'.$baseinfo['host'];
    if(substr(@$srcinfo['path'], 0, 1) == '/') {
        $path = $srcinfo['path'];
    }else {
        #当未从根目录开始时
        $filename = @basename($baseinfo['path'], '.index.html');    #返回文件名
        if(strpos($filename, ".") === false) {
            #当baseurl是一个目录时，新的url须带上该子目录
            $path = dirname(@$baseinfo['path']).'/'.$filename.'/'.@$srcinfo['path'];
        }else {
            #反之，则直接跟在baseurl之后
            $path = dirname($baseinfo['path']).'/'.$srcinfo['path'];
        }
    }
    $rst = array();
    $path_array = explode('/', $path);
    if(!$path_array[0]) {
        $rst[] = '';
    }
    #去除index.html结尾 如/products/index.html与/products/实际上为同一个链接
    $url_end_pattern = "/index\.[a-z]{3,5}/";
    preg_match($url_end_pattern, end($path_array), $end_url_match);
    if(@$end_url_match[0]) {
        $path_array[count($path_array) - 1] = '';
    }
    foreach($path_array as $key => $dir) {
        if($dir == '..') {
            if(end($rst) == '..') {
                $rst[] = '..';
            }elseif(!array_pop($rst)) {
                $rst[] = '..';
            }
        }elseif($dir && $dir != '.') {
            $rst[] = $dir;
        }
    }
    #链接/products与链接/products/其实表示的是同一个页面 此处处理
    if(!end($path_array)) {
        $rst[] = '';
    }
    $url .= implode('/', $rst);
    $urlinfo = parse_url($url);
    $url_host = str_replace('\\', '', $urlinfo['host']);
    $url_path = substr(@$urlinfo['path'], 0, 1) === '/' ? @$urlinfo['path'] : '/'.@$urlinfo['path'];
    $url = $urlinfo['scheme']."://".$url_host.$url_path;
    return str_replace('\\', '/', $url);
}

/**
 * 站内站外链接处理
 * @param  string  $url     url链接
 * @param  string  $baseurl baseurl链接
 * @param  boolean $type    为false时 则表示二级域名或多级域名为非本站链接
 * @return boolean           站内链接返回true 站外链接返回false
 */
function check_chain($url, $baseurl, $type=true) {
    $urlinfo = check_url_valid($url);
    $baseinfo = check_url_valid($baseurl);
    $special_domain_exts = array(
        ".com.cn"
    );
    if($urlinfo['host'] === $baseinfo['host']) {
        return true;
    }

    if($type) {
        for($i=0;$i<count($special_domain_exts);$i++) {
            if(!strrpos($baseinfo['host'], $special_domain_exts[$i])) {
                $normal_url_pattern = "/.*?(\w+\.\w+$)/i";
                preg_match($normal_url_pattern, $urlinfo['host'], $url_match);
                preg_match($normal_url_pattern, $baseinfo['host'], $base_match);
                if(@$url_match[1] === @$base_match[1]) {
                    return true;
                }
            }else {
                $ext_pattern = str_replace(".", "\.", $special_domain_exts[$i]);
                $domain_pattern = "/\w+$ext_pattern/";
                preg_match($domain_pattern, $urlinfo['host'], $url_match);
                preg_match($domain_pattern, $baseinfo['host'], $base_match);
                if($url_match === $base_match) {
                    return true;
                }
            }
        }
    }
    return false;
}

/**
 * 去除无效链接
 */
function trim_link($link) {
    $linkinfo = parse_url($link);
    $invalid_link_pattern = "/.*?([# :])/";
    $scheme = @$linkinfo['scheme'];
    $host = @$linkinfo['host'];
    $path = @$linkinfo['path'];
    preg_match($invalid_link_pattern, $host.$path, $m);
    if($scheme === "http" || $scheme === "https" && !@$m[1] && $host) {
        return $link;
    }
}

/**
 * 抓取单个页面
 * @param  string  $url    url地址
 * @param  boolean $output 是否输出数据
 * @return mixed           不输出数据时返回boolean,输出数据时返回数据
 */
function get_url($url, $output = true) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows; U; Windows NT 5.1; rv:1.7.3) Gecko/20041001 Firefox/0.10.1");
    curl_setopt($ch, CURLOPT_HEADER, 0);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, $output);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);

    if(strpos($url, 'https')) {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    }
    $content = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if($httpCode == 404) {
        return false;
    }
    curl_close($ch);
    return $output ? $content : true;
}

/**
 * 多线程抓页面
 * @param  array $urls url数组
 * @return array       抓取后的信息数组
 */
function get_thread_url($urls) {
	if (!is_array($urls) or count($urls) == 0) {  
        return false;  
    }   
    $num=count($urls);  
    $curl = $text = array();  
    $handle = curl_multi_init();  
    
    foreach($urls as $k=>$url){
    	$curl[$k] = curl_init($url);
        curl_setopt ($curl[$k], CURLOPT_URL, $url);
        curl_setopt ($curl[$k], CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 6.1; Trident/7.0; rv:11.0) like Gecko');//设置头部
        curl_setopt ($curl[$k], CURLOPT_REFERER, $url); //设置来源
        curl_setopt ($curl[$k], CURLOPT_ENCODING, "gzip"); // 编码压缩
        curl_setopt ($curl[$k], CURLOPT_RETURNTRANSFER, 1);
        curl_setopt ($curl[$k], CURLOPT_FOLLOWLOCATION, 1);//是否采集301、302之后的页面
        curl_setopt ($curl[$k], CURLOPT_MAXREDIRS, 5);//查找次数，防止查找太深
        curl_setopt ($curl[$k], CURLOPT_TIMEOUT, 20);
        curl_setopt ($curl[$k], CURLOPT_HEADER, 0);//输出头部
        if(strpos($url, 'https')) {
            curl_setopt($curl[$k], CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($curl[$k], CURLOPT_SSL_VERIFYHOST, false);
        }
        curl_multi_add_handle ($handle,$curl[$k]);
    }  
    $active = null;  
    do {  
        $mrc = curl_multi_exec($handle, $active);  
    } while ($mrc == CURLM_CALL_MULTI_PERFORM);  
  
    while ($active && $mrc == CURLM_OK) {  
        if (curl_multi_select($handle) != -1) {  
            usleep(100);  
        }  
        do {  
            $mrc = curl_multi_exec($handle, $active);  
        } while ($mrc == CURLM_CALL_MULTI_PERFORM);  
    }   
  
    foreach ($curl as $k => $v) {  
        if (curl_error($curl[$k]) == "") {  
            $text[$urls[$k]] = (string) curl_multi_getcontent($curl[$k]);   
        }
        if(curl_getinfo($curl[$k], CURLINFO_HTTP_CODE) == 404) {
            $text[$urls[$k]] = 404;
        }
        curl_multi_remove_handle($handle, $curl[$k]);  
        curl_close($curl[$k]);
    }   
    curl_multi_close($handle);  
    return $text;  
}

/**
 * 文件路径有效性检测
 * @param  string $path 文件路径
 * @return array        路径信息
 */
function check_path_valid($path) {
    $pathinfo = pathinfo($path);
    if(!isset($pathinfo['extension'])) {
        die("Error:路径{$path}无效，请输入完整的文件路径，包含文件名<br>");
    }
    return $pathinfo;
}

/**
 * 数据保存
 * @param  string $dest 数据保存路径
 * @param  string $data 数据
 * @param  string $type 写入方式
 * @return void 无返回值
 */
function save_data($dest, $data, $type='w') {
    $pathinfo = check_path_valid($dest);
    $save_dir = $pathinfo['dirname'];
    if(!file_exists($save_dir)) {
        mkdir($save_dir, 0777, true);
    }
    $fw = fopen($dest, $type);
    fputs($fw, $data);
    fclose($fw);
}

/**
 * 主函数：提取站内所有链接
 * @param  string $url          入口链接
 * @param  string $crawled_logs 已抓取链接日志保存路径
 * @param string $error_logs    错误链接日志保存路径
 * @param  array  $ignore_urls  需要忽略的url数组
 * @return void 无返回值
 */
function get_site_links($url, $crawled_logs, $error_logs, $ignore_urls = array()) {

    $urlinfo = check_url_valid($url);
    
    if(!@$urlinfo['path']) {
        $url = $url."/";
    }else {
    	if(substr(@$urlinfo['path'], -1, 1) !== "/") {
    		$url = $url."/";
    	}
    }

    $file = 'links.txt'; #临时文件

    $pending_urls = array();#待抓取的链接数组
    $crawled_urls = array();#已爬过的链接数组
    $error_urls = array();#错误链接数组
    $pending_ex_urls = array();
    $pending_urls["url"] = array();
    $pending_urls["from"] = array();
    array_push($pending_urls["url"], $url);

    do{

        @ob_flush();
        @flush();

        if(!empty($pending_ex_urls)) {
            foreach($pending_ex_urls as $pending_ex_url) {
                $pending_ex_url_array = explode("||", $pending_ex_url);
                array_push($pending_urls["url"], trim($pending_ex_url_array[0]));
                $pending_urls["from"][$pending_ex_url_array[0]] = trim($pending_ex_url_array[1]);
            }
        }

        #去除重复
        $pending_crawl_urls = array_unique($pending_urls["url"]);

        #检测是否已在已爬过数组中，并去除已抓取过的链接
        foreach($pending_crawl_urls as $key => $pending_crawl_url) {
            $pending_crawl_url = trim($pending_crawl_url);
            if(in_array($pending_crawl_url, $crawled_urls)) {
                unset($pending_crawl_urls[$key]);
                unset($pending_urls["from"][$pending_crawl_url]);
                continue;
            }else {
                $pending_urls["url"][$key] = $pending_crawl_url;
            }
            #检测是否在忽略的url数组中
            if(!empty($ignore_urls)) {
                if(!is_array($ignore_urls)) {
                    return false;
                }
                foreach ($ignore_urls as $ignore_url) {
                    if(strpos($pending_crawl_url, $ignore_url) !== false) {
                        unset($pending_crawl_urls[$key]);
                        unset($pending_urls["from"][$pending_crawl_url]);
                        continue;
                    }
                }
            }
        }
        #去除数组中空值
        array_filter($pending_urls);
        array_filter($pending_crawl_urls);

        echo "发现新链接".count($pending_crawl_urls)."个，";

        if(!$pending_crawl_urls) {
            echo '抓取结束！<br>';
            break;
        }

        #抓取待抓取数组中的链接
        $datas = get_thread_url($pending_crawl_urls);

        foreach ($pending_crawl_urls as $pending_crawl_url) {
            array_push($crawled_urls, $pending_crawl_url);#将抓取过的链接添加到已抓数组中
            save_data($crawled_logs, $pending_crawl_url."\r\n", 'ab');#将已爬过的链接保存到日志
        }

        echo "当前已抓取".count($crawled_urls)."个链接。\r\n<br>";

        #抓取获取抓到的数据中的链接
        if($datas) {
            foreach($datas as $k => $data) {
                if($data === 404) {
                    #将404错误的链接保存到错误url数组中
                    if(!in_array($k, $error_urls)) {
                        array_push($error_urls, $k.",".$pending_urls["from"][$k]);
                    }
                }else {
                    $page_links = get_links($k, $data)['link']['inner'];
                    if($page_links) {
                        #检测链接是否在已抓取过的数组中,如不在则写入文本文件中
                        foreach($page_links as $page_link) {
                            if(!in_array(trim($page_link), $crawled_urls)) {
                                save_data($file, $page_link."||{$k}\r\n", 'ab');
                            }
                        }
                    }
                }
            }
        }

    }while(($pending_ex_urls = file($file)));

    #保存错误的链接信息
    if(!empty($error_urls)) {
        $error_urls_str = implode("\r\n", $error_urls);
        save_data($error_logs, $error_urls_str, 'w');

        #错误链接数组
        $error_url_array = array();
        foreach ($error_urls as $error_ex_url) {
            $error_url_ex_array = explode(",", $error_ex_url);
            array_push($error_url_array, $error_url_ex_array[0]);
        }
        #重新写入已爬取过的链接数据，将错误的链接去除
        foreach($crawled_urls as $key => $crawled_url) {
            if(in_array($crawled_url, $error_url_array)) {
                unset($crawled_urls[$key]);
            }
        }
        array_filter($crawled_urls);
        $crawled_urls_str = implode("\r\n", $crawled_urls);
        save_data($crawled_logs, $crawled_urls_str, 'w');
    }

    #打印日志
    echo "发现错误链接 ".count($error_urls)." 个，正常链接 ".count($crawled_urls)." 个。<br>";

    #释放资源
    unset($pending_urls);
    unset($crawled_urls);
    unset($error_urls);
    unset($pending_ex_urls);
    @unlink($file);

    echo "程序执行结束！";

}

set_time_limit(10000);
ignore_user_abort(false);

$ignore_urls = array(
    "fr.example.com",
    "ru.example.com",
    "pt.example.com",
    "es.example.com"
);

get_site_links('http://www.example.com', 'logs/crawled_links.log', 'logs/error_links.log', $ignore_urls);
