<?php
  ini_set ("display_errors" , true);
  error_reporting (E_ALL);

  function logit ($param, $res = "", $log = "") {
    $line = date ("Y-m-d H:i:s") . "\t" . $param . ($res ? "\t" . $res : "") . ($log ? "\t" . $log : "") . "\n";
    if ($logfile = fopen ("transmission-remote.log", "a")) {
      fwrite ($logfile, $line);
      fclose ($logfile);
    }
  }

  function command ($param = "", $log = "") {
    $out = false;
    if ($line = exec ("transmission-remote " . $param, $out)) {
      if (count ($out) > 1) {
        $res = rtrim ($out[0]);
      } else {
        if (preg_match ("/\"(.+)\"/", $line, $reg)) $res = $reg[1];
      }
      if ($log !== false) logit ($param, (!empty ($res) ? $res : ""), $log);
    }
    return $out;
  }

  function getlist () {
    $tasks = array ();
    $out = command ("--list", false);
    if (is_array ($out)) {
      $header = preg_split ("/ +/", trim($out[0]));
      for ($i = 1; $i < count ($out); $i++) {
        $line = rtrim ($out[$i]);
        if (!preg_match ("/^sum:/i", $line)) {
          $vals = preg_split ("/ {2,}/", trim($line));
          $fields = array_combine ($header, $vals);
          $tasks[$fields["ID"]] = $fields;
        }
      }
    }
    return $tasks;
  }

  if (empty ($_SERVER["argv"][1])) $_SERVER["argv"][1] = "--help";
  $args = $_SERVER["argv"];
  if ($args[1] == "--add-rss" && !empty ($args[2])) {
    $hash = sha1 ($args[2]);
    if (is_readable ("transmission-rss-" . $hash . ".date")) $last = rtrim (file_get_contents ("transmission-rss-" . $hash . ".date"));
    if ($curl = curl_init ($args[2])) {
      curl_setopt ($curl, CURLOPT_HEADER, true);
      curl_setopt ($curl, CURLOPT_NOBODY, true);
      curl_setopt ($curl, CURLOPT_RETURNTRANSFER, true);
      if (($reply = curl_exec ($curl)) && preg_match ("/last\-modified:(.+)/i", $reply, $reg)) {
        $date = strtotime (rtrim ($reg[1]));
        if (empty ($last) || $date > $last) {
          if (($xml = simplexml_load_file ($args[2])) && !empty ($xml->channel->item) && count ($xml->channel->item)) {
            foreach ($xml->channel->item as $obj) {
              $date = (!empty ($obj->pubDate) ? strtotime ((string) $obj->pubDate) : 0);
              $name = (!empty ($obj->title) ? (string) $obj->title : "");
              $torrent = (!empty ($obj->link) ? (string) $obj->link : "");
              $site = (!empty ($obj->comments) ? (string) $obj->comments : "");
              if (!empty ($date) && !empty ($torrent) && !empty ($name)) {
                if (empty ($last) || $date > $last) {
                  if (empty ($DATE) || $date > $DATE) $DATE = $date;
                  command ("--add " . escapeshellarg ($torrent), $name);
                }
              }
            }
          }
        }
      }
      curl_close ($curl);
    }
    if (!empty ($DATE)) {
      if ($datefile = fopen ("transmission-rss-" . $hash . ".date", "w")) {
        fwrite ($datefile, $DATE);
        fclose ($datefile);
      }
    }
  } elseif (preg_match ("/^\-\-torrent=([0-9]+)/", $args[1], $reg) && ($task = $reg[1]) && !empty ($args[2]) && preg_match ("/^\-\-((no\-)?get|priority\-(high|low|normal))\-all$/", $args[2], $reg) && ($command = $reg[1])) {
    if ($tasks = getlist ()) {
      if ($out = command ($args[1] . " --files")) {
        if (preg_match ("/\(([0-9]+) files\):$/", $out[0], $reg)) {
          command ($args[1] . " --" . $command . "=" . implode (",", range (0, $reg[1]-1)), (!empty ($tasks[$task]) ? $tasks[$task]["Name"] : ""));
        }
      }
    }
  } elseif (preg_match ("/^\-\-list\-format=(.+)$/", $args[1], $reg)) {
    if ($tasks = getlist ()) {
      foreach ($tasks as $id => $task) {
        echo preg_replace ("/\{(.+)\}/eU", '(isset ($task["$1"]) ? $task["$1"] : null)', preg_replace ("/\\\\t/", "\t", $reg[1])) . "\n";
      }
    }
  } else {
    unset ($_SERVER["argv"][0]);
    array_walk ($_SERVER["argv"], create_function ('&$arg, $dummy', 'if (preg_match ("/[^a-z0-9=\-]/", $arg)) $arg = escapeshellarg ($arg);'));
    echo implode ("\n", command (implode (" ", $_SERVER["argv"]), (in_array ($args[1], array ("--help", "--list")) ? false : "")))."\n";
    if ($args[1] == "--help") {
      echo "\n";
      echo str_pad ("  --add-rss", 50) . "+  Watch RSS feed by url, auto add new entries\n";
      echo str_pad ("  --get-all", 50) . "+  Mark ALL files for download\n";
      echo str_pad ("  --no-get-all", 50) . "+  Mark ALL files for not downloading\n";
      foreach (array ("high", "normal", "low") as $val) {
        echo str_pad ("  --priority-" . $val . "-all", 50) . "+  Set ALL files' priorities as " . $val . "\n";
      }
      echo str_pad (str_pad ("  --list-format", 32) . "<pattern>", 50) . "+  List all torrents in the specified FORMAT\n";
      echo str_pad ("", 53) . "example: --list-format=foo{Name}bar{Ratio}\n";
    }
  }
?>