<html>
    <head>
        <meta http-equiv="refresh" content="3">
    </head>
    <body>
        <?php
            $lines = explode("\n", file_get_contents("./log.txt"));

            for ($i = count($lines) - 1; $i >= 0; $i--) {
                echo $lines[$i] . "<br/>";
            }
        ?>
    </body>
</html>
