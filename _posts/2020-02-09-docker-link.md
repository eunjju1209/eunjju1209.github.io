---
layout: post
title: Docker link
categories: Docker
excerpt_separator:  <!--more-->
---

- docker link 란?

docker container 연결 시키는 방법

https://docs.docker.com/engine/examples/postgresql_service/


—link 

내가 현재, snack bar 컨테이너를 띄우고있었는데 
db를 AWS 에서 제공하는게 아니라 컨테이너 디비를 열어서 사용하고 싶다. 

왜냐하면 docker volume 쓰고, git 에다가 올려놓으면 서버를 사지않아도 내 로컬에서 작업한걸 다른분이 사용할 수 있기ㄸㅐ문에.. 
혹시 맞지않으면 수정하려고 한다.

하이튼 snackbar 컨테이너오ㅏ psql 컨테이너를 연결을 어떻게 시켜서 내 로컬에서 테스트 할 수 있는 방법이 있는지 찾아보았는데
docker link 라는것이 있어서 알아보았다.
