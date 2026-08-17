	param (
	[switch]$Loop = $false
)

if(Test-Path "bin\php\php.exe"){
	$env:PHPRC = ""
	$binary = "bin\php\php.exe"
}else{
	$binary = "php"
}

# The new ECS server entry point (the old PocketMine.php was removed in the
# Phase-8 API rewrite).
$file = "bootstrap.php"
if(-not (Test-Path $file)){
	echo "Couldn't find a valid PocketMine-MP installation"
	pause
	exit 1
}

function StartServer{
	$command = $binary + " -d memory_limit=512M -d extension=ffi -d ffi.enable=1 " + $file + " --enable-ansi"
	iex $command
}

$loops = 0

StartServer

while($Loop){
	if($loops -ne 0){
		echo ("Restarted " + $loops + " times")
	}
	$loops++
	echo "To escape the loop, press CTRL+C now. Otherwise, wait 5 seconds for the server to restart."
	echo ""
	Start-Sleep 5
	StartServer
}