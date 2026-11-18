<?php
namespace App\Test;

use App\ConfigurationValues;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ConfigurationValuesTest extends TestCase {
	#[DataProvider("deploymentCredentialKeys")]
	public function testConstruct_rejectsEnvironmentCredentials(string $key):void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionCode(400);
		$this->expectExceptionMessage("managed key");
		$sut = new ConfigurationValues(json_encode(["values" => [$key => "private-token"]], JSON_THROW_ON_ERROR));
	}

	/** @return list<array{string}> */
	public static function deploymentCredentialKeys():array {
		return [["deployment.staging_token"], ["deployment.production_token"], ["deployment.production_token_server"]];
	}
}
