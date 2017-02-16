<?php
class Mailer_bulk{
    
    static
        $send_count = 5 // Number of emails per batch.
        , $send_interval = 300 // Minimum seconds between batches.
        , $subs_table_name = 'subscriptions'
        , $users_table_name = 'users'
        , $mailer_bulk_tables = array(
            'email_queue' => array(
                'schema' => "
id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
time INTEGER NOT NULL ON CONFLICT REPLACE DEFAULT (strftime('%s','now')),
ping INTEGER NOT NULL ON CONFLICT REPLACE DEFAULT (strftime('%s','now')),
subject TEXT,
message TEXT,
to_group TEXT,
remaining TEXT,
targets TEXT
"
            )
        )
        , $db
        , $queue_table_name
        , $refresh_time = false
        , $do_admin = false
        , $subs
        , $groups
        , $lock_file = './mailer_lock.txt' // Prevents more than one execution per cycle.
    ;
    
    function __construct($do_admin=false){
        static::$do_admin = $do_admin;
        
        // Delete lock file if it's old.
        if(
            is_readable(static::$lock_file)
            AND (time() - file_get_contents(static::$lock_file)) > static::$send_interval
        ){
            @unlink(static::$lock_file);
        }
        
        if( empty(static::$queue_table_name) ){
            foreach( static::$mailer_bulk_tables as $table => $schema ){
                static::$queue_table_name = $table;
                break;
            }
            include_once('class/Database.php');
            static::$db = new Database;
            $db_err = static::$db->get_error();
            if( ! empty($db_err) ){
                die(get_called_class().': Can\'t open DB.');
            }
            $db_check = static::$db->check_add_tables(static::$mailer_bulk_tables);
            $db_err = static::$db->get_error();
            if( ! empty($db_err) ){
                die(get_called_class().': '.$db_err);
            }
        }
        
        // Read subs.
        $read_data = static::$db->read(static::$subs_table_name);
        $db_err = static::$db->get_error();
        if(
            empty($read_data[0])
            OR ! empty($db_err)
        ){
            // Read failure.
            die(get_called_class().': Read subs: Can\'t read DB: '.$db_err);
        }
        static::$subs = $read_data;
        static::$groups = array();
        foreach( static::$subs[0] as $prop => $val ){
            if( $prop == 'id' ){continue;}
            static::$groups[] = $prop;
        }
        
        // Pick up any $_POST.
        $post_result = static::do_post();
        
        // Check for pending chrons.
        $cron_result = static::do_chron();
        
        @session_start();
        if(
            empty(static::$do_admin)
            OR empty($_SESSION['user']['username'])
            OR $_SESSION['user']['username'] !== 'Admin'
        ){
            return;
        }
        
// Pseudo-chron over.  Admin only from here on.
        
        // Show the form.
        $form_result = static::do_form();
        
        $return = '';
        $return .= '
<!DOCTYPE html>
<html>
<head>
    <title>Bulk Mailer</title>
    <style>
        #countdown{
            display:inline-block;
            border:1px solid black;
            padding:0 0.1em;
            font-weight:bold;
            font-family:sans-serif;
        }
    </style>';
        
        if(
            ! empty($_GET['auto_refresh'])
            AND static::$refresh_time !== false
        ){
            $return .= '
    <meta http-equiv="refresh" content="'.(static::$refresh_time + 1).'">';
            $auto_refresh = true;
        }
        $return .= '
</head>
<body>
<h1>Bulk Mailer</h1>
    <p>Sending '.static::$send_count.' messages every '.static::$send_interval.' seconds ('.(static::$send_interval/60).' minutes).</p>
    <h3><a title="Reload without POST or GET." href="?">Reload w/o Auto-Refresh</a></h3>';
        
        $return .= $post_result;
        $return .= $cron_result;
        $return .= $form_result;
        
        $return .= '
</body>
</html>
';
        echo $return;
        flush();
        
    // END of __construct().
    }
    
    static private function do_chron(){
        // Read chrons.
        $read_data = static::$db->read(static::$queue_table_name);
        $db_err = static::$db->get_error();
        if( ! empty($db_err) ){
            // Read failure.
            die(get_called_class().': Chron: Can\'t read DB: '.$db_err);
        }
        
        $chrons = array();
        $chrons_ready = array();
        $total = 0;
        $time = time();
        $time_diff = static::$send_interval;
        
        foreach( $read_data as $chron_key => $chron ){
            // Skip those not ready or already finished.
            if( empty($chron['remaining']) ){continue;} // Finished.
            $chrons[$chron['id']] = $chron;
            $total += count(explode(',',$chron['remaining']));
            if(
                $chron['ping'] >= ( $time - static::$send_interval )
            ){
                $time_next = $chron['ping'] + static::$send_interval;
                $time_diff_temp = $time_next - $time;
                if( $time_diff_temp < $time_diff ){
                    $time_diff = $time_diff_temp;
                }
                continue; // Not ready yet.
            }
            $chrons_ready[$chron['id']] = $chron;
        }
        
        if( count($chrons) ){
            static::$refresh_time = $time_diff;
        }
        
        $batch_count = 0;
        foreach( $chrons_ready as $chron_key => $chron ){
            $chron['ready'] = array();
            $targets = explode(',',$chron['remaining']);
            foreach( $targets as $target  ){
                $batch_count++;
                $chron['ready'][$target] = $target; // Yep, key and val.
                if( $batch_count >= static::$send_count ){
                    break;
                }
            }
            $chrons_ready[$chron_key] = $chron;
            if( $batch_count >= static::$send_count ){
                break;
            }
        }

        $return = '';
        if(
            ! empty($batch_count)
            // Create, lock and write time to lock file.
            AND $lock_handle = @fopen(static::$lock_file,'x+')
            AND @flock($lock_handle,LOCK_EX)
            AND @fwrite($lock_handle,time())
        ){
            
            // Chron action.
            
            $return .= '
<hr/>
<h3>Chron triggered.</h3>
<p>';
            
            // Read users.
            $read_data = static::$db->read(static::$users_table_name);
            $db_err = static::$db->get_error();
            if(
                empty($read_data[0])
                OR ! empty($db_err)
            ){
                // Read failure.
                die(get_called_class().': Chron: Can\'t read DB: '.$db_err);
            }
            
            // Order users by ID.  CRITICAL!
            $users = array();
            foreach( $read_data as $user ){
                if( empty($user['rank']) OR $user['rank'] < 1 ){ continue; }
                $users[$user['id']] = $user;
            }
            
            include_once('class/Mailer.php');
            $mailer = new Mailer;
            $mail_success = false;
            $mail_err = '';
            
            ignore_user_abort(true);
            
            foreach( $chrons_ready as $chron_key => $chron ){
                unset($subject,$message);
                if( ! empty($chron['ready']) ){
                    $subject = $chron['subject'];
                    $message = html_entity_decode($chron['message'],ENT_QUOTES);
                    $return .= '
Queue ID '.$chron['id'].': "'.$chron['subject'].'" to "'.$chron['to_group'].'" ('.count(explode(',',$chron['remaining'])).' remaining)<br/>';
                    $chron['sent'] = array();
                    foreach( $chron['ready'] as $target ){
                        unset($user,$name,$email);
                        if(
                            empty($users[$target]['rank'])
                            OR $users[$target]['rank'] < 1
                        ){continue;}
                        $user = $users[$target];
                        if(
                            ! isset($user['id'])
                            OR $user['id'] != $target
                        ){
                            die('Mailer_bulk Error: $user[\'id\'] != $target. Please tell the admin.');
                        }
                        
                        // Get ready to send mail.
                        $name = $user['username'];
                        $email = $user['email'];
                        
                        // Send it!
                        $mail_success = $mailer->send_message($message, $subject, $name, $email);
                        $mail_err = $mailer->get_error();
                        
                        if( empty($mail_success) ){ // Mail failure.
                            $return .= get_called_class().': '.$mail_err.'<br/>';
                            return $return;
                        }
                        $total--;
                        $chron['sent'][$target] = $target; // Yep, key and val.
                        $return .= '
&nbsp;&nbsp;&nbsp;&nbsp;Sent to: "'.$name.'" &lt;'.$email.'&gt;<br/>';
                    }
                }
                
                if( ! empty($chron['sent']) ){
                    $remaining = explode(',',$chron['remaining']);
                    $remaining_new = array();
                    foreach( $remaining as $target ){
                        if( ! in_array($target,$chron['sent']) ){
                            $remaining_new[] = $target;
                        }
                    }
                    $chron['remaining'] = implode(',',$remaining_new);
                }
                
                unset($chron['ready']);
                unset($chron['sent']);
                
                $chron['ping'] = time();
                $write_data = array(
                    array_keys($chron),
                    array_values($chron),
                );
                $table = static::$queue_table_name;
                $row_count = static::$db->write($table,$write_data,true);
                $db_err = static::$db->get_error();
                if(
                    empty($row_count)
                    OR ! empty($db_err)
                ){
                    // Write failure.
                    die(get_called_class().': '.$db_err);
                }
                $chrons_ready[$chron_key] = $chron;
                $chrons[$chron_key] = $chron;
            }
            $return .= '
</p>
<p>Now there are '.$total.' total remaining.</p>';

            // Delete lock file if it belongs to this process.
            if(
                @flock($lock_handle,LOCK_UN)
                AND @fclose($lock_handle)
            ){
                @unlink(static::$lock_file);
            }
        }
        
        if( ! empty($total) ){
            $return .= '
<h3>'.count($chrons).' Chrons Pending</h3>
<p>';
            foreach( $chrons as $chron_key => $chron ){
                $return .= '
Queue ID '.$chron['id'].': "'.$chron['subject'].'" to "'.$chron['to_group'].'" ('.count(explode(',',$chron['remaining'])).' remaining)<br/>';
            }
            $return .= '<br/>
Totaling '.$total.' remaining, with <span id="countdown">'.$time_diff.'</span> seconds until next batch. ';
            if(
                ! empty($_GET['auto_refresh'])
                AND static::$refresh_time !== false
            ){
                $return .= '<strong>Auto-Refresh is Active</strong>';
            }else{
                $return .= '<a title="Don\'t use while composing!" href="?auto_refresh=true">Enable Auto-Refresh</a>';
            }
            $return .= '
<script type="text/javascript"><!--
    function countDownTimer(){
        countDownElem = document.getElementById(\'countdown\');
        timeLeft = 0+countDownElem.innerHTML;
        if( timeLeft > 0 ){
            timeLeft--;
            countDownElem.innerHTML = timeLeft;
        }
    }
    setInterval(countDownTimer,1000);
//--></script></p>';
        }else{
            static::$refresh_time = false;
        }
        
        return $return;
    }
    
    static private function do_post(){
        @session_start();
        if(
            empty(static::$do_admin)
            OR empty($_SESSION['user']['username'])
            OR $_SESSION['user']['username'] !== 'Ezriilc'
        ){
            return;
        }
        
        // Scrub $_POST to $post
        if(
            empty($_POST['mailer_bulk'])
            OR empty($_SESSION['post_token'])
            OR $_POST['mailer_bulk'] !== $_SESSION['post_token']
            OR empty($_POST['group'])
            OR (
                $_POST['group'] != 'ALL'
                AND ! in_array($_POST['group'],static::$groups)
            )
            OR empty($_POST['subject'])
            OR strlen($_POST['subject']) > 255
            OR empty($_POST['message'])
            OR strlen($_POST['message']) > 10000
        ){return;}
        $post = array('group'=>htmlentities($_POST['group']));
        $post['subject'] = htmlentities($_POST['subject']);
        $post['message'] = htmlentities($_POST['message']);
        unset($_POST);
        
        // Determine mail recipients.
        $read_data = static::$db->read('users');
        $db_err = static::$db->get_error();
        if(
            empty($read_data[0])
            OR ! empty($db_err)
        ){
            // Read failure.
            die(get_called_class().': Read users: Can\'t read DB: '.$db_err);
        }
        $users = $read_data;
        
        $targets = array();
        foreach( $users as $user ){
            if( empty($user['rank']) OR $user['rank'] < 1 ){ continue; }
            $id = $user['id'];
            if( $post['group'] == 'ALL' ){
                $targets[] = $id;
            }else{
                foreach( static::$subs as $sub ){
                    if( $sub['id'] != $id ){continue;}
                    if( ! empty($sub[$post['group']]) ){
                        $targets[] = $id;
                    }
                    break;
                }
            }
        }
        
        // Add message to the queue.
        $count = count($targets);
        $targets = implode(',',$targets);
        $write_data = array(
            array( // Fields
                'subject','message','to_group','remaining','targets'
            ),
            array( // Values
                $post['subject'],$post['message'],$post['group'],$targets,$targets
            ),
        );
        $row_count = static::$db->write(static::$queue_table_name,$write_data,true);
        $db_err = static::$db->get_error();
        if(
            empty($row_count)
            OR ! empty($db_err)
        ){
            // Write failure.
            die(get_called_class().': DB: Can\'t write to '.static::$queue_table_name);
        }
        
        $return = '';
        $return .= '
<hr/>
<h3>Email Queued</h3>
<p>Added "'.$post['subject'].'" to "'.$post['group'].'" with '.$count.' recipients.</p>';

        return $return;
    }
    
    
    
    static private function do_form(){
        if(
            ! empty($_GET['auto_refresh'])
            AND static::$refresh_time !== false
        ){return;}
        
        $_SESSION['post_token'] = uniqid(null,true);
        
        $return = '';
        $return .= '
<hr/>
<h3>Compose New Email</h3>
<form name="mailer_bulk_form" method="post" enctype="multipart/form-data"><fieldset>
    <div style="position:relative;left:-2px;top:-4px;"><small><strong>Email Form</strong> - <em>Fields with * are required.</em></small></div>
    <div style="clear:both;"></div>
    <div style="padding:0 2px; border:1px solid black; clear:both;">To: *<br/>';
        $i=0;
        foreach( static::$groups as $group ){
            if( ! $i ){ $checked = ' checked="checked"'; }
            else{ $checked = ''; }
            $return .= '
        <input type="radio" id="group_'.$i.'" name="group" value="'.$group.'"'.$checked.'/>
        <label for="group_'.$i.'">'.$group.'</label>
        <br/>';
            $i++;
        }
        $return .= '
        <input type="radio" id="group_ALL" name="group" value="ALL"/>
        <label for="group_ALL">ALL users - CAUTION!</label>
    </div>
    <div style="clear:both;"></div>
    <br/>
    <div style="clear:both;">
        <label for="subject" style="display:inline-block;min-width:6em; float:left;">Subject: *</label>
        <input type="text" id="subject" name="subject" value="" maxlength="100" style="width:66%;"/>
    </div>
    <div style="clear:both;"></div>
    <div style="clear:both;">
        <label for="message" style="clear:both;">Message: *</label><br/>
        <textarea id="message" name="message" cols="70" rows="30" style="width:70em;max-width:99%;height:20em;"></textarea>
    </div>
    <div style="clear:both;"></div>
    <br/>
    <div>
        <input type="hidden" name="mailer_bulk" value="'.@$_SESSION['post_token'].'" />
        <input type="submit" value="Send" />
    </div>
    <div style="clear:both;"></div>
</fieldset></form>';
        return $return;
    }
}
?>
