<?php
namespace GT\Deploy;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use RuntimeException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;

// Keep authentication and redirect policy together for deployment API endpoints.
/** @SuppressWarnings("PHPMD.ExcessiveClassComplexity") */
readonly class DeploymentClient {
	public function __construct(private ClientInterface $client = new Client()) {
	}

	public function reserve(string $server, ?string $token = null):Reservation {
		$headers = ["Accept" => "application/json"];
		if($token !== null) {
			$headers["Authorization"] = "Bearer $token";
		}

		$response = $this->client->request("POST", "$server/deployment/", [
			"headers" => $headers, "connect_timeout" => 5, "timeout" => 15,
			"allow_redirects" => $this->redirects("$server/deployment/"), "http_errors" => false,
		]);
		$status = $response->getStatusCode();
		if($status === 410 && $token !== null) {
			return $this->reserve($server);
		}

		if($status < 200 || $status >= 300) {
			$message = match($status) {
				401 => "Invalid deployment token (HTTP 401). Check deploy.token before retrying.",
				default => "Deployment reservation failed (HTTP $status).",
			};
			throw new RuntimeException($message);
		}

		$reservation = $this->readReservation((string)$response->getBody(), $server);
		if($token !== null && !hash_equals($token, $reservation->token)) {
			throw new RuntimeException("The server returned a different deployment token.");
		}

		return $reservation;
	}

	private function readReservation(string $body, string $server):Reservation {
		$reservation = json_decode($body, flags: JSON_THROW_ON_ERROR);
		if(!is_object($reservation)
		|| !is_string($reservation->url ?? null)
		|| !filter_var($reservation->url, FILTER_VALIDATE_URL)
		|| !in_array(parse_url($reservation->url, PHP_URL_SCHEME), ["http", "https"], true)
		|| !is_string($reservation->token ?? null)
		|| !preg_match('/^[0123456789ABCDEFGHJKMNPQRSTVWXYZ]{42}$/D', $reservation->token)
		|| !is_string($reservation->upload_url ?? null)) {
			throw new RuntimeException("The deployment server returned an invalid reservation.");
		}

		return new Reservation($reservation->url, $reservation->token, $this->uploadUrl($server, $reservation->upload_url));
	}

	/** @SuppressWarnings("PHPMD.StaticAccess") */
	private function uploadUrl(string $server, string $uploadUrl):string {
		$base = new Uri("$server/");
		$upload = UriResolver::resolve($base, new Uri($uploadUrl));
		if([$upload->getScheme(), $upload->getAuthority()] !== [$base->getScheme(), $base->getAuthority()]) {
			throw new RuntimeException("The upload URL must use the configured deployment server.");
		}

		return (string)$upload;
	}

	/** @param callable(int, int):void $progress */
	public function upload(Reservation $reservation, string $archive, callable $progress):void {
		$stream = fopen($archive, "rb");
		if($stream === false) {
			throw new RuntimeException("Unable to open deployment archive.");
		}

		try {
			$response = $this->client->request("POST", $reservation->uploadUrl, [
				"headers" => ["Accept" => "application/json", "Content-Type" => "application/gzip", "Authorization" => "Bearer $reservation->token"],
				"body" => $stream, "connect_timeout" => 5, "timeout" => 300,
				"allow_redirects" => $this->redirects($reservation->uploadUrl), "http_errors" => false,
				"progress" => function(float $downloadTotal, float $downloaded, float $uploadTotal, float $uploaded) use ($progress):void {
					unset($downloadTotal, $downloaded);
					$progress((int)$uploaded, (int)$uploadTotal);
				},
			]);
			$status = $response->getStatusCode();
			if($status < 200 || $status >= 300) {
				throw new RuntimeException("Archive upload failed (HTTP $status). Run gt deploy to retry using the saved token.");
			}

			$result = json_decode((string)$response->getBody(), flags: JSON_THROW_ON_ERROR);
			if(!is_object($result) || ($result->status ?? null) !== "uploaded") {
				throw new RuntimeException("The deployment server did not confirm the upload.");
			}
		}
		finally {
			if(is_resource($stream)) {
				fclose($stream);
			}
		}
	}

	public function initialise(string $server, string $token):void {
		$url = "$server/initialise/";
		$response = $this->client->request("POST", $url, [
			"headers" => ["Accept" => "application/json", "Authorization" => "Bearer $token"],
			"connect_timeout" => 5, "timeout" => 90,
			"allow_redirects" => $this->redirects($url), "http_errors" => false,
		]);
		$status = $response->getStatusCode();
		$body = (string)$response->getBody();
		if($status < 200 || $status >= 300) {
			$result = json_decode($body);
			$detail = is_object($result) && is_string($result->error ?? null)
				? ": " . str_replace($token, "[redacted]", $result->error)
				: "";
			throw new RuntimeException("Deployment initialisation failed (HTTP $status)$detail");
		}

		$result = json_decode($body, flags: JSON_THROW_ON_ERROR);
		if(!is_object($result) || ($result->status ?? null) !== "prepared") {
			throw new RuntimeException("The deployment server did not confirm source extraction.");
		}
	}

	/** @param callable(string):void $progress */
	public function build(string $server, string $token, callable $progress):void {
		$deadline = time() + 1800;
		$method = "POST";
		$previous = "";
		do {
			$result = $this->buildRequest($method, "$server/build/", $token);
			$status = $result->status;
			if($status === "deployed") {
				return;
			}
			if($status === "failed") {
				$message = is_string($result->error ?? null)
					? str_replace($token, "[redacted]", $result->error) : "Deployment build failed.";
				throw new RuntimeException($message);
			}
			if(!in_array($status, ["queued", "building"], true)) {
				throw new RuntimeException("The deployment server returned an invalid build status.");
			}
			if($status !== $previous) {
				$progress($status);
				$previous = $status;
			}
			$method = "GET";
			sleep(1);
		} while(time() < $deadline);
		throw new RuntimeException("Timed out waiting for the deployment build. Check the server worker and build.log.");
	}

	private function buildRequest(string $method, string $url, string $token):object {
		$response = $this->client->request($method, $url, [
			"headers" => ["Accept" => "application/json", "Authorization" => "Bearer $token"],
			"connect_timeout" => 5, "timeout" => 15,
			"allow_redirects" => $this->redirects($url), "http_errors" => false,
		]);
		$body = (string)$response->getBody();
		$status = $response->getStatusCode();
		if($status < 200 || $status >= 300) {
			throw new RuntimeException("Deployment build request failed (HTTP $status).");
		}
		$result = json_decode($body, flags: JSON_THROW_ON_ERROR);
		if(!is_object($result) || !is_string($result->status ?? null)) {
			throw new RuntimeException("The deployment server returned an invalid build response.");
		}
		return $result;
	}

	/**
	 * @param array<string, string> $values
	 * @param callable(string):void $queued
	 */
	public function setConfiguration(string $server, string $token, array $values, callable $queued):void {
		$id = $this->queueConfiguration($server, $token, $values, $queued);
		$deadline = time() + 1800;
		do {
			$status = $this->configurationRequest("GET", "$server/configuration/?job=$id", $token);
			if(($status->status ?? null) === "applied") {
				return;
			}
			if(($status->status ?? null) === "failed") {
				$message = is_string($status->error ?? null) ? str_replace($token, "[redacted]", $status->error) : "Configuration update failed.";
				throw new RuntimeException($message);
			}
			if(!in_array($status->status ?? null, ["queued", "applying"], true)) {
				throw new RuntimeException("The server returned an invalid configuration status.");
			}
			sleep(1);
		} while(time() < $deadline);
		throw new RuntimeException("Timed out waiting for the configuration worker.");
	}

	/**
	 * @param array<string, string> $values
	 * @param callable(string):void $queued
	 */
	private function queueConfiguration(string $server, string $token, array $values, callable $queued):string {
		$result = $this->configurationRequest("POST", "$server/configuration/", $token, ["values" => $values]);
		if(!is_string($result->id ?? null) || !preg_match('/^[a-f0-9]{16}$/D', $result->id)
		|| !is_string($result->url ?? null) || !filter_var($result->url, FILTER_VALIDATE_URL)) {
			throw new RuntimeException("The deployment server returned an invalid configuration job.");
		}
		$queued($result->url);
		return $result->id;
	}

	/** @param array<string, mixed> $body */
	private function configurationRequest(string $method, string $url, string $token, array $body = []):object {
		$options = [
			"headers" => ["Accept" => "application/json", "Authorization" => "Bearer $token"],
			"connect_timeout" => 5, "timeout" => 15,
			"allow_redirects" => $this->redirects($url), "http_errors" => false,
		];
		if($method === "POST") {
			$options["json"] = $body;
		}
		$response = $this->client->request($method, $url, $options);
		$code = $response->getStatusCode();
		if($code < 200 || $code >= 300) {
			$message = match($code) {
				410 => "The deployment expired. Run gt deploy, then retry config-set.",
				409 => "The deployment is not ready for configuration changes. Wait for gt deploy to finish.",
				default => "Configuration request failed (HTTP $code).",
			};
			throw new RuntimeException($message);
		}
		$result = json_decode((string)$response->getBody(), flags: JSON_THROW_ON_ERROR);
		if(!is_object($result)) {
			throw new RuntimeException("The server returned an invalid configuration response.");
		}
		return $result;
	}

	/** @return list<string> */
	public function getConfiguredKeys(string $server, string $token):array {
		$url = "$server/configuration/";
		$response = $this->client->request("GET", $url, [
			"headers" => ["Accept" => "application/json", "Authorization" => "Bearer $token"],
			"connect_timeout" => 5, "timeout" => 15,
			"allow_redirects" => $this->redirects($url), "http_errors" => false,
		]);
		$status = $response->getStatusCode();
		if($status < 200 || $status >= 300) {
			throw new RuntimeException("Deployment configuration inspection failed (HTTP $status).");
		}
		$result = json_decode((string)$response->getBody(), flags: JSON_THROW_ON_ERROR);
		$keys = $result->keys ?? null;
		if(!is_array($keys) || !array_is_list($keys) || count(array_filter($keys, "is_string")) !== count($keys)) {
			throw new RuntimeException("The server returned invalid configuration keys.");
		}
		return $keys;
	}

	/** @return array<string, mixed> */
	private function redirects(string $url):array {
		$origin = new Uri($url);
		return [
			"max" => 3, "strict" => true, "protocols" => ["http", "https"],
			"on_redirect" => function(RequestInterface $request, ResponseInterface $response, UriInterface $destination) use ($origin):void {
				unset($request);
				if(!in_array($response->getStatusCode(), [307, 308], true)
				|| $destination->getScheme() !== $origin->getScheme()
				|| $destination->getAuthority() !== $origin->getAuthority()) {
					throw new RuntimeException("Deployment requests only follow same-origin 307 or 308 redirects.");
				}
			},
		];
	}
}
