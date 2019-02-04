<?php

$filename = "file.txt";
// if file does not exist, create the file with initial counter value set to 0
if (!file_exists($filename)){
    file_put_contents($filename, 0, LOCK_EX);
}

if (isset($_GET["action"])) {
    /* update part: plus or minus one to the counter */

    $filehandler = fopen($filename, 'r+');
    if (!$filehandler) {
        die("Unable to open file");
    }

    // first we get a lock before doing anything else
    // Retry every 0.2 second
    while (!flock($filehandler, LOCK_EX)) {
        usleep(200000);
    }

    // retrieve the counter value from file
    $counter = (int)fgets($filehandler);

    if ($_GET["action"] == "minus"){
            $counter--;
    } elseif ($_GET["action"] == "plus"){
            $counter++;
    }

    // first we empty the file,
    ftruncate($filehandler, 0);
    // then we go to the beginning of the file
    rewind($filehandler);
    // then we can write content,
    fwrite($filehandler, $counter);
    //unlocking the file
    flock($filehandler, LOCK_UN);
    // and close the file
    fclose($filehandler);

    echo "OK";

} elseif (isset($_GET["sse"])) {
    /* Server Sent Events part */

    // by putting an impossible value, this will always send a value on connection
    $counter = null;
    header("Content-Type: text/event-stream");
    header("Cache-Control: no-cache");
    header("Connection: keep-alive");
    header("X-Accel-Buffering: no");

    $filehandler = fopen($filename, 'r');
    $i = 0;

    // Tell the client to retry to connect after one second
    echo "retry: 1000\n";
    $contentInBuffer = true;

    // the server polls the file for any change, and only push the new value to the client if there is a change
    while (true) {

        // echo a ping approximately every 10 seconds
        $i++;
        if ($i % 20 === 0){
            $contentInBuffer = true;
            echo "event: ping\n";
            echo "data: pong\n\n";
        }

        rewind($filehandler);
        $latestReadCounter = fgets($filehandler);

        if ($latestReadCounter !== $counter and is_numeric($latestReadCounter) ) {
            $counter = $latestReadCounter;
            echo "event: counter\n";
            echo "data: $counter\n\n";
            $contentInBuffer = true;
        }

        if ($contentInBuffer){
            // flush the output buffer and send echoed messages to the browser
            while (ob_get_level() > 0) {
                ob_end_flush();
            }
            flush();
            $contentInBuffer = false;
        }

        // break the loop if the client aborted the connection (closed the page)
        if ( connection_aborted() ) break;

        // sleep for half a second before running the loop again
        usleep(500000);
    }
    fclose($filehandler);

} else {
    /* deliver the application UI to the client */
?>
<html>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, user-scalable=no" />
        <style>
            html, body {
                touch-action: manipulation;
                user-select: none;
                margin: 0;
                width: 100%;
                height: 100%;
                overflow: hidden;
                color: #ecf0f1;
                font-family: sans-serif;
            }

            body {
                display: flex;
                flex-wrap: wrap;
            }

            .display {
                background-color: #2d3436;
                width: 100%;
                height: 30%;
                font-size: 20vmin;
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: space-around;
            }

            .networkWarning, .localUpdate {
                color: #f1c40f;
            }

            .networkWarning {
                white-space: nowrap;
                font-size: 5vmin;
            }

            button, button:active, button:focus {
                border: 0;
                outline: 0;
                padding: 0;
                width: 50%;
                height: 70%;
                font-size: 42vmin;
                color: inherit;
                display: flex;
                align-items: center;
                justify-content: center;
            }


            #minus {
                background-color: #ee3030;
            }

            #minus:active {
                background-color: #ce0000;
            }

            #plus {
                background-color: #00ae30;
            }

            #plus:active {
                background-color: #007e00;
            }

            .flashingBg{
                animation: flashing 1s infinite;
            }

            @keyframes flashing {
                0%,49% {background-color: #2d3436;}
                50%,100% {background-color: red;}
            }
        </style>
    </head>

    <body>
        <div class="display">
            <span id="counter">...</span>
            <span class="networkWarning" hidden>Server connection lost</span>
        </div>
        <button onclick="buttonClick(this)" id="minus">−</button>
        <button onclick="buttonClick(this)" id="plus">+</button>
        <script>
        let minusBtn = document.getElementById("minus");
        let plusBtn  = document.getElementById("plus");
        let localCounter = 0;
        let counterSpan = document.getElementById("counter");
        let networkWarning = document.querySelector(".networkWarning");
        let timeoutID;

        function updateCounter(value, isLocalClick){
            if (value >= 1000){
                counterSpan.textContent = "! " + value + " !";
                document.querySelector(".display").classList.add("flashingBg");
            } else {
                document.querySelector(".display").classList.remove("flashingBg");
                counterSpan.textContent = value;
            }

            if (isLocalClick) {
                counterSpan.classList.add("localUpdate");
            } else {
                counterSpan.classList.remove("localUpdate");
            }
        }

        function xhr(url){
            let xhr = new XMLHttpRequest();
            xhr.open("GET", url, true);
            xhr.responseType = "text";
            xhr.onreadystatechange = function () {
                if (this.readyState === 4) {
                    if (this.status !== 200){
                        networkWarning.hidden = false;
                    } else {
                        networkWarning.hidden = true;
                    }
                }
            }
            xhr.send();
        }

        function buttonClick(e){
            let url = "?action=" + e.id;
            xhr(url);
            if (e.id === "plus"){
                localCounter++;
            } else if (e.id === "minus") {
                localCounter--;
            }
            updateCounter(localCounter, true);
        }

        let eventSource = new EventSource("?sse");
        eventSource.addEventListener("counter", function(evt) {
            networkWarning.hidden = true;
            localCounter = evt.data;
            updateCounter(localCounter, false);
        });

        eventSource.addEventListener("ping", function(evt) {
            networkWarning.hidden = true;
            if (timeoutID) clearTimeout(timeoutID);
            timeoutID = setTimeout(function(){
                networkWarning.hidden = false;
            }, 15000);
        });

        eventSource.onreadystatechange = function(){
            console.info("readyState:", eventSource.readyState)
        }

        eventSource.onerror = function(err) {
            console.error(err);
            networkWarning.hidden = false;
        }

        window.onbeforeunload = function() {
            eventSource.close();
        }
        </script>
    </body>
</html>

<?php
}
