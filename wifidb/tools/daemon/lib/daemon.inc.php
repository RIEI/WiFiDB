<?php
/*
Daemon.inc.php, holds the WiFiDB daemon functions.
Copyright (C) 2011 Phil Ferland

This program is free software; you can redistribute it and/or modify it under the terms
of the GNU General Public License as published by the Free Software Foundation; either
version 2 of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY;
without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
See the GNU General Public License for more details.

ou should have received a copy of the GNU General Public License along with this program;
if not, write to the

   Free Software Foundation, Inc.,
   59 Temple Place, Suite 330,
   Boston, MA 02111-1307 USA
*/

class daemon extends wdbcli
{
    public function __construct($config, $daemon_config)
    {
        parent::__construct($config, $daemon_config);
        $this->time_interval_to_check = $daemon_config['time_interval_to_check'];
        $this->default_user         = $daemon_config['default_user'];
        $this->default_title        = $daemon_config['default_title'];
        $this->default_notes        = $daemon_config['default_notes'];
        $this->convert_extentions   = array('csv','db','db3','vsz');
        $this->ver_array['Daemon']  = array(
                                    "last_edit"             =>  "2013-May-27",
                                    "CheckDaemonKill"       =>  "1.0",#
                                    "cleanBadImport"        =>  "1.0",
                                    "GenerateUserImport"    =>  "1.0",
                                    "insert_file"           =>  "1.0",
                                    "parseArgs"             =>  "1.0"
                                    );
    }
####################
    /**
     * @return int
     */
    public function CheckDaemonKill()
    {
        $D_SQL = "SELECT * FROM `wifi`.`settings` WHERE `table` = 'daemon_state'";
        $Dresult = $this->sql->conn->query($D_SQL);
        $daemon_state = $Dresult->fetchall();

        if($daemon_state[0]['size']=="0")
        {
            $this->exit_msg = "Daemon was told to kill itself";
            return 1;
        }else
        {
            $this->exit_msg = NULL;
            return 0;
        }
    }

    /**
     * @param string $user
     * @param string $notes
     * @param string $title
     * @param string $hash
     * @return array
     * @throws ErrorException
     */
    function GenerateUserImportIDs($user = "", $notes = "", $title = "", $hash = "")
    {
        if($user === "")
        {
            throw new ErrorException("GenerateUserImportIDs was passed a blank username, this is a fatal exception.");
        }
        $multi_user = explode("|", $user);
        $rows = array();
        $n = 0;
        # Now lets insert some preliminary data into the User Import table as a place holder for the finished product.
        $sql = "INSERT INTO `wifi`.`user_imports` ( `id` , `username` , `notes` , `title`, `hash`) VALUES ( NULL, ?, ?, ?, ?)";
        $prep = $this->sql->conn->prepare($sql);
        foreach($multi_user as $muser)
        {
            if ($muser === ""){continue;}
            $prep->bindParam(1, $muser, PDO::PARAM_STR);
            $prep->bindParam(2, $notes, PDO::PARAM_STR);
            $prep->bindParam(3, $title, PDO::PARAM_STR);
            $prep->bindParam(4, $hash, PDO::PARAM_STR);
            $prep->execute();

            if($this->sql->checkError())
            {
                $this->logd("Failed to insert Preliminary user information into the Imports table. :(", "Error");
                $this->verbosed("Failed to insert Preliminary user information into the Imports table. :(\r\n".var_export($this->sql->conn->errorInfo(), 1), -1);
                Throw new ErrorException;
            }
            $n++;
            $rows[$n] = $this->sql->conn->lastInsertId();
            $this->logd("User ($muser) import row: ".$rows[$n]);
            $this->verbosed("User ($muser) import row: ".$rows[$n]);
        }
        return $rows;
    }


    function cleanBadImport($hash = "")
    {
        $sql1 = "DELETE FROM `wifi`.`files_tmp` WHERE `hash` = ?";
        $prep = $this->sql->conn->prepare($sql1);
        $prep->bindParam(1, $hash, PDO::PARAM_STR);
        $prep->execute();
        if($this->sql->checkError())
        {
            $this->verbosed("Failed to remove bad file from the tmp table.".var_export($this->sql->conn->errorInfo(),1), -1);
            $this->logd("Failed to remove bad file from the tmp table.".var_export($this->sql->conn->errorInfo(),1));
            throw new ErrorException("Failed to remove bad file from the tmp table.");
        }else
        {
            $this->verbosed("Cleaned file from the Temp table.");
        }

        $sql1 = "DELETE FROM `wifi`.`user_imports` WHERE `hash` = ?";
        $prep = $this->sql->conn->prepare($sql1);
        $prep->bindParam(1, $hash, PDO::PARAM_STR);
        $prep->execute();
        if($this->sql->checkError())
        {
            $this->verbosed("Failed to remove bad file from the tmp table.".var_export($this->sql->conn->errorInfo(),1), -1);
            $this->logd("Failed to remove bad file from the tmp table.".var_export($this->sql->conn->errorInfo(),1));
            throw new ErrorException("Failed to remove bad file from the tmp table.");
        }else
        {
            $this->verbosed("Cleaned file from the User Import table.");
        }
    }

    /**
     * @param $file
     * @param $file_names
     * @return int
     * @throws ErrorException
     */
    public function insert_file($file, $file_names)
    {
        $source = $this->PATH.'import/up/'.$file;
        echo $source."\r\n";
        $hash = hash_file('md5', $source);
        $size1 = $this->format_size(filesize($source));
        if(@is_array($file_names[$hash]))
        {
            $user = $file_names[$hash]['user'];
            $title = $file_names[$hash]['title'];
            $notes = $file_names[$hash]['notes'];
            $date = $file_names[$hash]['date'];
            $hash_ = $file_names[$hash]['hash'];
        }else
        {
            $user = $this->default_user;
            $title = $this->default_title;
            $notes = $this->default_notes;
            $date = date("y-m-d H:i:s");
            $hash_ = $hash;

        }
        $this->logd("=== Start Daemon Prep of ".$file." ===");

        $sql = "INSERT INTO `wifi`.`files_tmp` ( `id`, `file`, `date`, `user`, `notes`, `title`, `size`, `hash`  )
                                                                VALUES ( '', '$file', '$date', '$user', '$notes', '$title', '$size1', '$hash')";
        $prep = $this->sql->conn->prepare($sql);
        $prep->bindParam(1, $file, PDO::PARAM_STR);
        $prep->bindParam(2, $date, PDO::PARAM_STR);
        $prep->bindParam(3, $user, PDO::PARAM_STR);
        $prep->bindParam(4, $notes, PDO::PARAM_STR);
        $prep->bindParam(5, $title, PDO::PARAM_STR);
        $prep->bindParam(6, $size1, PDO::PARAM_STR);
        $prep->bindParam(7, $hash, PDO::PARAM_STR);
        $prep->execute();

        $err = $this->sql->conn->errorInfo();
        if($err[0] == "00000")
        {
            $this->verbosed("File Inserted into Files_tmp. ({$file})\r\n");
            $this->logd("File Inserted into Files_tmp.".$sql);
            return 1;
        }else
        {

            $this->verbosed("Failed to insert file info into Files_tmp.\r\n".var_export($this->sql->conn->errorInfo(),1));
            $this->logd("Failed to insert file info into Files_tmp.".var_export($this->sql->conn->errorInfo(),1));
            throw new ErrorException;
        }
    }

    /**
     * @param $argv
     * @return array
     */
    function parseArgs($argv)
    {
        array_shift($argv);
        $out = array();
        foreach ($argv as $arg)
        {
            if (substr($arg,0,2) == '--'){
                $eqPos = strpos($arg,'=');
                if ($eqPos === false){
                    $key = substr($arg,2);
                    $out[$key] = isset($out[$key]) ? $out[$key] : true;
                } else {
                    $key = substr($arg,2,$eqPos-2);
                    $out[$key] = substr($arg,$eqPos+1);
                }
            } else if (substr($arg,0,1) == '-'){
                if (substr($arg,2,1) == '='){
                    $key = substr($arg,1,1);
                    $out[$key] = substr($arg,3);
                } else {
                    $chars = str_split(substr($arg,1));
                    foreach ($chars as $char){
                        $key = $char;
                        $out[$key] = isset($out[$key]) ? $out[$key] : true;
                    }
                }
            } else {
                $out[] = $arg;
            }
        }
        return $out;
    }

#END DAEMON CLASS
}
?>