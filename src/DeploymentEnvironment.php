<?php
namespace GT\Deploy;

enum DeploymentEnvironment:string {
	case Staging = "staging";
	case Production = "production";
}
