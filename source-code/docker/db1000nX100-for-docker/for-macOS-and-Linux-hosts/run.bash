#!/usr/bin/env bash

localImage=0
imageLocal=db1000nx100-image-local
cpuArch=$(uname -m)
dockerHost=$(uname)

if     [ "$cpuArch" == "aarch64" ] || [ "$cpuArch" == "arm64" ]; then
    image="ihorlv/db1000nx100-image-arm64v8"
    container="db1000nx100-container-arm64v8"
elif   [ "$cpuArch" == "x86_64" ]; then
    image="ihorlv/db1000nx100-image"
    container="db1000nx100-container"
else
  echo "No container for your CPU architecture $cpuArch"
  sleep 10
  exit
fi

if ! docker container ls; then
   echo ========================================================================
   echo Docker not running. Please, start Docker Desktop and restart this script
   echo ========================================================================
   sleep 3
   exit
fi

##################################################################################################

function readinput() {
  local CLEAN_ARGS=""
  while [[ $# -gt 0 ]]; do
    local i="$1"
    case "$i" in
      "-i")
        if read -i "default" 2>/dev/null <<< "test"; then
          CLEAN_ARGS="$CLEAN_ARGS -i \"$2\""
        fi
        shift
        shift
        ;;
      "-p")
        CLEAN_ARGS="$CLEAN_ARGS -p \"$2\""
        shift
        shift
        ;;
      *)
        CLEAN_ARGS="$CLEAN_ARGS $1"
        shift
        ;;
    esac
  done
  eval read $CLEAN_ARGS
}

reset

if [ "$dockerHost" == "Linux" ]; then
  readinput -e -p "How much of your computer's CPU to use (10-100%)  ?   Press ENTER for 50% limit _" -i "50" cpuUsageLimit
  cpuUsageLimit=${cpuUsageLimit:=50}
  readinput -e -p "How much of your computer's RAM to use (10-100%)  ?   Press ENTER for 50% limit _" -i "50" ramUsageLimit
  ramUsageLimit=${ramUsageLimit:=50}
fi

readinput -e -p "How much of your network bandwidth to use (20-100%)     ?   Press ENTER for 90% limit _" -i "90" networkUsageLimit
networkUsageLimit=${networkUsageLimit:=90}

##################################################################################################

if [ "$networkUsageLimit" != "-1" ]; then
  docker container stop ${container}
  docker rm             ${container}
fi

if [ "$localImage" = 1 ]; then
    echo "==========Using local container=========="
    sleep 2
  	image=${imageLocal}
    docker load  --input "$(pwd)/../${image}.tar"
else
    docker pull ${image}:latest
fi

if ! cd ../put-your-ovpn-files-here; then
   echo =========================================
   echo Please, change your directory to run.bash
   echo =========================================
   sleep 3
   exit
fi

docker create --volume "$(pwd)":/media/put-your-ovpn-files-here  --privileged  --interactive  --name ${container}  ${image}
echo "dockerHost=${dockerHost};cpuUsageLimit=${cpuUsageLimit};ramUsageLimit=${ramUsageLimit};networkUsageLimit=${networkUsageLimit}" > "$(pwd)/db1000nX100-config-override.txt"
docker container start ${container}

if [ "$networkUsageLimit" == "-1" ]; then
    docker exec  --interactive  --tty  ${container}  /usr/bin/mc
else
    docker exec  --interactive  --tty  ${container}  /bin/sh -c "ulimit -Sn 65535 ; /root/DDOS/x100-suid-run.elf"
fi

echo "Waiting 10 seconds"
sleep 10
docker container stop ${container}
echo "Docker container stopped"