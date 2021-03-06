<?php

require_once dirname(__FILE__) . '/bootstrap.php';
require_once "vendor/mashape/unirest-php/src/Unirest.php";

$APIS = [
    "http://dogfish.tech/api/api1/lax",
    "http://dogfish.tech/api/api2/fr",
    "http://dogfish.tech/api/api3/usd,eur"
];


$app->get('/', function() use ($app, $db) {
    $list = [];
    $query = $db->apis();
    foreach ($query as $row) array_push($list, $row["name"]);

    $app->render("/index.html", array(
        "api" => API_HOST,
        "list" => $list
    ));
});

$app->get('/phpinfo', function() {
    return phpinfo();
});

/*$app->get("/status", function() use ($app, $APIS) {

    $headers = array("Accept" => "application/json");
    $body = array("foo" => "hellow", "bar" => "world");

    $res1 = Unirest\Request::post($APIS[0], $headers, $body);
    $res2 = Unirest\Request::post($APIS[1], $headers, $body);
    $res3 = Unirest\Request::post($APIS[2], $headers, $body);

    $code1 = $res1->body->headers->response_code;
    $code2 = $res2->body->headers->response_code;
    $code3 = $res3->body->headers->response_code;



    $response->code;        // HTTP Status code
    $response->headers;     // Headers
    $response->body;        // Parsed body
    $response->raw_body;    // Unparsed body


    $app->render("/status.html", array(
        "code1" => $code1,
        "code2" => $code2,
        "code3" => $code3
    ));
});*/

# Check the APIs every few seconds and record errors
$app->get("/check/:apiName", function($apiName) use ($app, $db, $APIS) {

    $endpoint = $db->apis()->where("name", $apiName)->fetch()["endpoint"];

    if (!$endpoint) {
        return $app->response->write("No such endpoint");
    }

    for ($i = 0; $i < POUNDING_SPEED; $i++) {
        $headers = array("Accept" => "application/json");
        $res = Unirest\Request::get($APIS[0], $headers);
        $code = $res->code;

        # Some 200 requests may not return a JSON object, but a string
        # If so, we assign them to 204
        if ($code == 200) {
            if (gettype($res->body) != "object") {
                $code = 204;
            }
        }

        $data = array("code" => $code);
        $query = $db->$apiName()->insert($data);
    }

    return $app->response->write("Done checking endpoint");
});

# Get all APIs
$app->get("/list", function() use ($app, $db) {
    $return_data = [];
    $query = $db->apis();
    foreach ($query as $row) array_push($return_data, $row["name"]);

    return $app->response->write(json_encode($return_data));
});

# Get data about a particular API
$app->get("/get/:apiName", function($apiName) use ($app, $db, $APIS) {

    # Check if API exists
    if (!$db->$apiName()->fetch()) return $app->response->write("No such endpoint");
    $data = $db->$apiName();

    $error_codes = ["204", "404", "500"];
    $return_data = array();

    # 200
    $return_data["200"] = count($data->where("code", 200));

    # Loop through each error type
    foreach ($error_codes as $error_code) {
        $data_db = $db->$apiName()->where("code", $error_code);
        $data_return = array(
            "count" => count($data_db),
            "times" => []
        );
        foreach ($data_db as $row) {
            array_push($data_return["times"], strtotime($row["timestamp"]));
        }
        $return_data[$error_code] = $data_return;
    }

    $sum = $return_data["200"]+$return_data["204"]["count"]+$return_data["404"]["count"]+$return_data["500"]["count"];
    $prop = $return_data["200"]/$sum;
    $uptime = round($prop*100, 2)."%";
    $return_data["uptime"] = $uptime;

    return $app->response->write(json_encode($return_data));
});

# Get data about a particular API for the given time sample
$app->get("/get/:apiName/:from/:to", function($apiName, $from_timestamp, $to_timestamp) use ($app, $db, $APIS) {

    # Check if API exists
    if (!$db->$apiName()->fetch()) return $app->response->write("No such endpoint");

    $data = $db->$apiName();
    $error_codes = ["204", "404", "500"];
    $return_data = array();

    $from = date("Y-m-d H:i:s", $from_timestamp);
    $to   = date("Y-m-d H:i:s", $to_timestamp);

    # 200
    $return_data["200"] = count($data->where("code", 200)->and("timestamp > ?", $from)->and("timestamp < ?", $to));
    # Loop through each error type
    foreach ($error_codes as $error_code) {
        $data_db = $db->$apiName()->where("code", $error_code)->and("timestamp > ?", $from)->and("timestamp < ?", $to);
        $data_return = array(
            "count" => count($data_db),
            "times" => []
        );
        foreach ($data_db as $row) {
            array_push($data_return["times"], strtotime($row["timestamp"]));
        }
        $return_data[$error_code] = $data_return;
    }

    return $app->response->write(json_encode($return_data));
});

# Add a new API to check
$app->post("/add-api", function() use ($app, $db) {

    $name = $app->request->post("name");
    $endpoint = $app->request->post("endpoint");

    # Create a new table for the API
    $conn = new mysqli(DB_HOST, DB_USERNAME, DB_PASSWORD, DB_NAME);
    $sql1 = "
        CREATE TABLE `".$name."` (
            `id` int(11) NOT NULL,
            `code` int(3) NOT NULL,
            `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=latin1;

    ";
    $sql2 = "
        ALTER TABLE `".$name."`
        ADD PRIMARY KEY (`id`);
    ";
    $sql3 = "
        ALTER TABLE `".$name."`
        MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;
    ";

    $res1 = $conn->query($sql1);
    $res2 = $conn->query($sql2);
    $res3 = $conn->query($sql3);

    $conn->close();

    # Is there an API with the same name?
    if ($db->apis()->where("name", $name)->fetch()) {
        $app->response->setStatus(400);
        return $app->response->write("API with such a name exists in the system");
    }

    # Add the API to the api table
    $data = array(
        "name" => $name,
        "endpoint" => $endpoint
    );
    $query = $db->apis()->insert($data);

    # If API has been added successfully
    if ($query) {
    # if (true) {

        # Append the endpoint to the Raspberry crontab
        $headers = array("Accept" => "application/json");
        $body = array("name" => $name);
        $res = Unirest\Request::post("http://jule.chickenkiller.com/stirhack/", $headers, $body);

        return $app->response->write($res->body);

        /*if ($res) {
            $app->render("/index.html", array());
            return 0;
        }*/

    } else {
        $app->response->setStatus(500);
        return $app->response->write("Unable to add API to the system");
    }

});

# Send an SMS about a requested API statistics
$app->post("/smsroute", function() use ($app, $db) {

    # $response = new Services_Twilio_Twiml;
    $body = strtolower($_REQUEST['Body']);

    # Log request for debugging
    # file_put_contents("/sms/log.txt", $body.PHP_EOL, FILE_APPEND);

    $words = explode(" ", $body);

    # Hello
    if ($words[0] == "hello") {
        header("content-type: text/xml");
        $string = "<?xml version=\"1.0\" encoding=\"UTF-8\"?><Response><Message>...

Hello, I am API Doctor. Try sending me \"list\" for a list of available APIs or \"stats (api name)\" for realtime statistics for a particular endpoint.</Message></Response>";
        return $app->response->write($string);

    # Send a list of API names
    } else if ($words[0] == "list") {

        $name_list = [];
        $query = $db->apis();
        foreach ($query as $row) array_push($name_list, $row["name"]);
        $string = implode(", ", $name_list);

        header("content-type: text/xml");
        $string = "<?xml version=\"1.0\" encoding=\"UTF-8\"?><Response><Message>...

List of available APIs:

" . $string . "</Message></Response>";
        return $app->response->write($string);

    # Send statistics about APIs
    } else if ($words[0] == "stats") {

        $name_list = [];
        $query = $db->apis();
        foreach ($query as $row) array_push($name_list, $row["name"]);

        if (!in_array($words[1], $name_list)) {
            header("content-type: text/xml");
            $string = "<?xml version=\"1.0\" encoding=\"UTF-8\"?><Response><Message>...

Err, I do not know that API.

Try sending \"stats (api name)\" or \"list\" for a list of APIs.</Message></Response>";
            return $app->response->write($string);

        # Else - this API exists - compute statistics
        } else {

            # Call this API for data
            $headers = array("Accept" => "application/json");
            $res = Unirest\Request::get("http://localhost/get/".$words[1], $headers);

            $data = json_decode(json_encode($res->body), true);

            $sum = $data["200"] + $data["204"]["count"]+$data["404"]["count"]+$data["500"]["count"];
            $uptime = (round(($data["200"]/$sum), 4)*100)."%";

            # Check for each error
            if ($data["204"]["count"] > 0) {
                $last_204 = "204: ".date("Y-m-d H:i:s", end($data["204"]["times"]));
            } else {
                $last_204 = "204: No faults registered";
            }

            if ($data["404"]["count"] > 0) {
                $last_404 = "404: ".date("Y-m-d H:i:s", end($data["404"]["times"]));
            } else {
                $last_404 = "404: No faults registered";
            }

            if ($data["500"]["count"] > 0) {
                $last_500 = "500: ".date("Y-m-d H:i:s", end($data["500"]["times"]));
            } else {
                $last_500 = "500: No faults registered";
            }

            header("content-type: text/xml");
            $string = "<?xml version=\"1.0\" encoding=\"UTF-8\"?><Response><Message>...

Statistics for ".$words[1].":

Uptime: ".$uptime."

Last most common errors:
".$last_204."
".$last_404."
".$last_500."
</Message></Response>";
            return $app->response->write($string);

        }


    }

    # Else - what the hell are you sending
    header("content-type: text/xml");
    $string = "<?xml version=\"1.0\" encoding=\"UTF-8\"?><Response><Message>...

Err, I don't know that command means.

Try sending \"stats (api name)\" or \"list\" for a list of APIs.</Message></Response>";
    return $app->response->write($string);

});

# View API page
$app->get("/view/:apiName", function($apiName) use ($app, $db) {

    $app->render("view.html", array(
        "api" => API_HOST,
        "apiname" => $apiName
    ));
});

# View Add API page
$app->get("/add", function() use ($app, $db) {

    $app->render("add.html", array(
        "api" => API_HOST,
    ));
});

$app->run();

