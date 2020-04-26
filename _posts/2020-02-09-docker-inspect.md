---
layout: post
title: Docker inspect
categories: Docker
excerpt_separator:  <!--more-->
comment:false
---

- docker inspect 란?

docker run 실행후, 매개변수느 command full 볼려면 inspect 치고 컨테이너 이름 치면 상세로 확인 할 수 있다.

> docker inspect stall(컨테이너)

컨테이너 관련 되 서 상세 내역을 볼 수 있다.

![image](https://user-images.githubusercontent.com/40929370/74098239-2f8a8080-4b59-11ea-824b-408a30f45686.png)

또는 저기서 필요한 데이터만 따로 추출 하고 싶은 경우에는 
-f 필터 걸어서 궁금한 부분만 오브젝트 형식으로 검색 하면 찾아볼 수 있다.

docker linke 공부하면서, 나의 redis 컨테이너 내부 ip가 궁금해서, 

docker inspect -f "{{.NetworkSettings.IPAddress}}" redis
쳐서 내부 ip 를 검색해서 알 수 있었다.
![image](https://user-images.githubusercontent.com/40929370/74098253-59dc3e00-4b59-11ea-8cb1-b144010cd1d7.png)
