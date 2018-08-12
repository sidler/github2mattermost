<?php

/**
 * A simple script to handle event notifications from github and forward them to a mattermost channel
 *
 * @author sidler@mulchprod.de
 * @license MIT
 */
class GithubHandler
{
    /** The secret provided right here needs to be set in the GitHub webhook, too */
    const GITHUB_SECRET = "placeYourSecretH1ere";

    /** This is the mattermost incoming webhook url - copy it from your mattermost config */
    const MATTERMOST_WEBHOOK = "PlaceYourIncomingWebhookUrlHere";






    public function process()
    {
        if (!isset($_SERVER['HTTP_X_HUB_SIGNATURE'])) {
            throw new RuntimeException("Missing 'X-Hub-Signature' header");
        }

        if (!isset($_SERVER['HTTP_X_GITHUB_EVENT'])) {
            throw new RuntimeException("Missing 'X-Github-Event' header");
        }

        $this->validateHash(file_get_contents('php://input'));

        $payload = $this->parsePayload();
        $message = $this->generateMessage($payload);
        $this->postToMattermost($message);
    }


    private function postToMattermost(array $message)
    {
        $options = array(
            'http' => array(
                'header'  => "Content-type: application/json\r\n",
                'method'  => 'POST',
                'content' => json_encode($message)
            ),
            'ssl' => array(
                'verify_peer' => false,
                'verify_peer_name' => false
            )
        );
        $context  = stream_context_create($options);
        if (file_get_contents(self::MATTERMOST_WEBHOOK, false, $context) === false) {
            throw new RuntimeException("Failed to post to mattermost");
        }
    }


    private function generateMessage(array $payload): array
    {
        switch (strtolower($_SERVER['HTTP_X_GITHUB_EVENT'])) {
            case 'create':
                return [
                    "username" => $payload['sender']['login'],
                    "text" => "Event: create ".$payload['ref'].",  Repo: ".$payload['repository']['full_name'].", User: ".$payload['sender']['login'],
                    "icon_url" => $payload['sender']['avatar_url']
                ];
                break;
            case 'delete':
                return [
                    "username" => $payload['sender']['login'],
                    "text" => "Event: delete ".$payload['ref'].",  Repo: ".$payload['repository']['full_name'].", User: ".$payload['sender']['login'],
                    "icon_url" => $payload['sender']['avatar_url']
                ];
                break;
            case 'push':
                $commits = [];
                foreach ($payload['commits'] as $commit) {
                    $commits[] = $commit['message'];

                    if (count($commits) > 15) {
                        $commits[] = "...";
                        break;
                    }
                }
                return [
                    "username" => $payload['sender']['login'],
                    "text" => "Event: push, Repo: ".$payload['repository']['full_name'].", Ref: ".$payload['ref'].", User: ".$payload['sender']['login'].PHP_EOL."Commits: ".implode(PHP_EOL, $commits),
                    "icon_url" => $payload['sender']['avatar_url']
                ];
                break;

            default:
                return ["username" => "bot", "text" => "No mapped event: ".$_SERVER['HTTP_X_GITHUB_EVENT']];
        }
    }

    private function parsePayload(): array
    {
        switch ($_SERVER['CONTENT_TYPE']) {
            case 'application/json':
                return json_decode(file_get_contents('php://input'), true);
                break;
            case 'application/x-www-form-urlencoded':
                return json_decode($_POST['payload'], true);
                break;
            default:
                throw new RuntimeException("Cant parse payload, unsupported content type");
        }
    }

    private function validateHash(string $payload)
    {
        if (!extension_loaded('hash')) {
            throw new RuntimeException("Missing 'hash' extension");
        }

        list($algo, $hash) = explode('=', $_SERVER['HTTP_X_HUB_SIGNATURE'], 2);

        if (!in_array($algo, hash_algos(), TRUE)) {
            throw new RuntimeException("Hash algorithm ".$algo." not available");
        }

        if (!hash_equals($hash, hash_hmac($algo, $payload, GithubHandler::GITHUB_SECRET))) {
            throw new RuntimeException('Hash not matching');
        }

    }

}

$objHandler = new GithubHandler();
try {
    $objHandler->process();

} catch (Throwable $t) {
    header('HTTP/1.1 500 Internal Server Error');
    echo "Error: ".$t->getMessage().PHP_EOL;
    die();
}
