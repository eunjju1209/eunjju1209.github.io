---
layout: post
title: Docker-Compose
comments: false
categories: Docker
---

회사에서 최근에 작업하는 프로젝트가 있는데 <br/>
이 프로젝트를 띄우려면 컨테이너가 3개가 띄어줘야지 내 로컬에서 실행 시킬 수 있었다.

<br/> 
근데 몇일 전 회사에서 git pull 받았더니 `docker-compose.yaml` 파일이 생겨있고, 
docker-compose 가 무엇인지 확인해봤더니, 
    `services` 밑에 있는 컨테이너 들을 한꺼번에 올리고 내리고 할 수 있습니다. 
 > docker-compose up 하면 
 docker-compose.yaml 에 있는 컨테이너들을 한번에 올릴 수 있고, 한번에 내릴 수 있습니다.
 
docker-compose up 을 하면 여러개

docker-compose up -d >> 백그라운드로 실행 시킬 수 있습니다.
    
