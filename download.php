<?php
	/**
	并发下载，大概方法需要自己调整代码
	*/
	function downloadMulti($download_url,$multi_num=2,$down_file_path='/tmp',$target_path='/tmp') {
        $header = Utility::httpGet($download_url,'','','','',true)[0];
        $download_url = $header['url'];
        $url_info = parse_url($download_url);
        $target_file_name = substr($url_info['path'], strrpos($url_info['path'], '/')+1);
        list($file_name,$file_type) = explode('.',  $target_file_name);
    
        $file_name_tmp = $file_name.".tmp";
        $down_file_tmp = $down_file_path."/".$file_name_tmp;
    
        $target_file   = $target_path."/".$target_file_name;
        
    
        if(file_exists($target_file)){
            echo "$target_file\tfile exists\tsize:".self::fileSizeConvert(filesize($target_file)),"\n";
            return $target_file;
        }
        if($header['download_content_length'] == 0){
            $log = array('failed','download error','download_content_length == 0');
            self::echoLog('err', $log);
            return false;
        }
        
        $min_size = 1024*1024*30;//10m 一个请求
        $header['download_content_length'] <= $min_size && $multi_num = 1;
        
        $size = ceil($header['download_content_length'] / $multi_num);
        $start = 0;
        $end   = $size;
        $file_names = [];
        $req_arr    = [];
        $s_time = microtime(true);
        for ( $i = 0; $i < $multi_num; $i++ ) {
            $rang = "Range:bytes=$start-$end";
            $req_header = array('User-Agent:Mozilla/5.0 (Linux; Android 6.0; Nexus 5 Build/MRA58N) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/63.0.3239.84 Mobile Safari/537.36',$rang);
            $start = $end+1;
            $end   = $start+$size;
            $req_name = "req_{$i}";
            $tmp_file_name = $down_file_tmp.".{$i}";
            if(!file_exists($tmp_file_name)){
                $req_arr[$req_name] = array(
                    'url'    => $download_url,
                    'header' => $req_header,
                    'timeout'=> 16
                );
            }
            $file_names[$req_name]= $down_file_tmp.".{$i}";
        }
        if(!empty($req_arr)) {
        $res = Utility::curlMulti($req_arr);
            foreach ($file_names as $key=>$tmp_path) {
                echo $tmp_path,"\n";
                isset($res[$key]) && file_put_contents($tmp_path, $res[$key]);
                if(!file_exists($tmp_path)){
                    $log = array('failed','download error','文件下载失败');
                    self::echoLog('err', $log);
                    return false;
                }
            }
        }
        $tmp_files = implode(' ', $file_names);
        $shell_merge_cmd = "cat $tmp_files > $target_file";
        $shell_del_cmd = "rm -f $tmp_files";
        shell_exec($shell_merge_cmd);
        shell_exec($shell_del_cmd);
        
        echo $target_file_name,"\t下载耗时：", round(microtime(true) - $s_time,3),"s,file size:".self::fileSizeConvert(filesize($target_file))."\n";
        return $target_file;
    }

	function fileSizeConvert($bytes) {
        $bytes = floatval($bytes);
        $arBytes = array(
            0 => array(
                "UNIT" => "TB",
                "VALUE" => pow(1024, 4)
            ),
            1 => array(
                "UNIT" => "GB",
                "VALUE" => pow(1024, 3)
            ),
            2 => array(
                "UNIT" => "MB",
                "VALUE" => pow(1024, 2)
            ),
            3 => array(
                "UNIT" => "KB",
                "VALUE" => 1024
            ),
            4 => array(
                "UNIT" => "B",
                "VALUE" => 1
            ),
        );
    
        foreach($arBytes as $arItem)
        {
            if($bytes >= $arItem["VALUE"])
            {
                $result = $bytes / $arItem["VALUE"];
                $result = strval(round($result, 2)).$arItem["UNIT"];
                break;
            }
        }
        return $result;
    }

	/**
     * curl 并发请求
     * Get
     * @param  $req_arr = array('test'=>array('url'=>'','timeout'=>100))
     * @return
     */
    function curlMulti($req_arr) {
        $timeout_is_ms = isset($val['timeout_is_ms']) ? true : false;
        $mh = curl_multi_init();
        $curlHandles = array();
        foreach ($req_arr as $key => $val) {
            $ch=curl_init();
            curl_setopt($ch, CURLOPT_URL, $val['url']);
            
            if (isset($val['agent'])) {
                curl_setopt($ch, CURLOPT_USERAGENT, $val['agent']);
            }
            
            if (isset($val['header'])) {
                curl_setopt($ch, CURLOPT_HTTPHEADER, $val['header']);
            }
            
            if ($timeout_is_ms) {
                curl_setopt($ch, CURLOPT_NOSIGNAL, true);
                curl_setopt($ch, CURLOPT_TIMEOUT_MS, $val['timeout']);
            }else{
                curl_setopt($ch, CURLOPT_TIMEOUT, $val['timeout']);
            }
            
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
//             curl_setopt($ch, CURLOPT_POST, true);
//             curl_setopt($ch, CURLOPT_POSTFIELDS, $val['post']);
            $curlHandles[$key] = $ch;
            curl_multi_add_handle ($mh,$ch);
        }
        
        $active = null;
        do {
            $mrc = curl_multi_exec($mh, $active);
        } while ($mrc == CURLM_CALL_MULTI_PERFORM);
        
        while ($active && $mrc == CURLM_OK) {
            while (curl_multi_exec($mh, $active) === CURLM_CALL_MULTI_PERFORM);
            if (curl_multi_select($mh) != -1) {
                do {
                    $mrc = curl_multi_exec($mh, $active);
                } while ($mrc == CURLM_CALL_MULTI_PERFORM);
            }
        }
        
        $result = array();
        
        foreach ($curlHandles as $key=>$ch) {
            $result[$key] = curl_multi_getcontent($ch);
            curl_multi_remove_handle($mh, $ch);
        }
        curl_multi_close($mh);
        return $result;
    }

	function echoLog($level,$log) {
        echo date('Y-m-d H:i:s'),"\tcrowler\t$level\t",implode("\t", $log),"\n";
    }

?>
