---
layout: post
title: GCP 웹서버 구축
comments: false
categories: GCP
---

내가 올려놓은 소스들을 실제로 웹서버 에서 테스트 해보기 위해서 gcp 에서 웹 서버를 만드는 방법을 찾아보았다.
프로젝트 생성을 했다는 전제조건으로 포스팅을 해보려고 한다.

<img width="406" alt="image" src="https://user-images.githubusercontent.com/40929370/87937174-31c11580-cacf-11ea-8204-4802fcd87c9f.png">

1. vm 인스턴스를 만들어 줘야한다. Compute Engine > VM 인스턴스

![image](https://user-images.githubusercontent.com/40929370/88022201-d2fcaa00-cb69-11ea-8662-d862a615d3ac.png)

> 인스턴스가 만들어지면 외부 IP 주소를 할당 받게되며, 이는  임시 IP 이지만 고정 IP로 할당받을 수있다.<br/>
> * 외부 IP 주소로 할당 방법<br/>
> 목록 > VPC 네트워크 > 외부 IP 주소 설정 하고, 유형을 고정으로 변경해준다.

그리고 나서, vm 인스턴스 목록에서 ssh 클라우드로 접속해준다.

인스턴스가 생성이 잘되었다면, ssh 접속이 가능하다.
![image](https://user-images.githubusercontent.com/40929370/88068844-b2554400-cbab-11ea-83e6-3b9eeed71c37.png)


2. 서버 연결

2-1. 기본 설정하기
```
# linux root 사용자로 변경 (ssh 연결 완료되면 제일 먼저 root로 변경해준다.)</span>
sudo su 

# 패키지 목록 갱신
apt-get update

# 현재 운영체제에 설치되어 있는 프로그램 최신버전 패치
apt-get upgrade

# 시스템 시간 설정
dpkg-reconfigure tzdata

# nginx 설치
apt-get install nginx-y

```

