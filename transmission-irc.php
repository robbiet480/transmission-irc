<?php
  ini_set ("display_errors" , true);
  error_reporting (E_ALL);

  function shutdown () {
    global $socket;
    if (!empty ($socket)) {
      write ("QUIT");
      fclose ($socket);
    }
  }

  function logit ($line, $tofile = true) {
    static $log;
    if ($tofile) {
      if (empty ($log)) {
        $log = fopen ("transmission-irc.log", "a");
        if ($log === false) die ("ERROR log open\n");
      }
      if (!fwrite ($log, (preg_match ("/^\s*$/", $line) ? "" : date ("Y-m-d H:i:s") . "\t") . $line . "\n")) die ("ERROR log write\n");
    }
    if (!preg_match ("/^\s*$/", $line)) echo date ("H:i:s") . "  " . $line . "\n";
  }

  function read () {
    global $socket;
    $line = rtrim (fgets ($socket, 512));
    $data = array ("from" => "", "type" => "", "to" => "", "body" => $line);
    if (preg_match ("/^(?:\:([^ ]+) )?([^ ]+) (?:([^ ]+) )?\:(.+)$/", $line, $reg)) {
      $data["from"] = $reg[1];
      $data["type"] = strtolower ($reg[2]);
      $data["user"] = $reg[3];
      $data["body"] = $reg[4];
    }
    if ($line) logit ("  >>\t" . $line, ($data["type"] == "ping" ? false : true));
    return $data;
  }

  function write ($data, $log = true) {
    global $socket;
    if (is_array ($data)) $line = "PRIVMSG " . $data[0] . " :" . $data[1];
    else $line = $data;
    logit ("<<\t" . $line, $log);
    if (!fwrite ($socket, $line . "\n")) die ("ERROR socket write\n");
  }

  set_time_limit (false);
  $conf = parse_ini_file ("transmission.conf", true);
  register_shutdown_function ("shutdown");

  $socket = fsockopen ($conf["irc"]["server_host"], $conf["irc"]["server_port"], $errno, $errstr, 15);
  if ($socket === false) die ("ERROR socket open " . $errno . " " . $errstr . "\n");
  if (!socket_set_blocking ($socket, true)) die ("ERROR socket set blocking\n");
  logit ("\n\n");
  write ("NICK " . $conf["irc"]["my_nick"]);
  write ("USER transmissi 8 * :" . $conf["irc"]["my_nick"]);
  write (array ("NickServ", "IDENTIFY " . $conf["irc"]["nickserv_pass"]));
  do {
    $data = read ();
    switch ($data["type"]) {
      case "001": if (preg_match ("/@([^@]+)$/", $data["body"], $reg)) $host = $reg[1]; break;
      case "ping": write ("PONG " . (!empty ($host) ? $host : "dummy"), false); break;
      case "privmsg":
        if (preg_match ("/^" . $conf["irc"]["master_nick"] . "\!/", $data["from"])) {
          $out = "";
          $command = explode (" ", $data["body"]);
          array_walk ($command, create_function ('&$arg, $dummy', 'if (preg_match ("/[^a-z0-9=\-]/", $arg)) $arg = escapeshellarg ($arg);'));
          exec ($conf["irc"]["exec"] . " " . implode (" ", $command), $out);
          foreach ($out as $line) {
            $line = rtrim ($line);
            if ($line) {
              write (array ($conf["irc"]["master_nick"], $line));
              if ($data["body"] != "--help" && $line == "See the man page for detailed explanations and many examples.") break;
              usleep (200000);
            }
          }
        }
      break;
    }
  } while (true);
?>