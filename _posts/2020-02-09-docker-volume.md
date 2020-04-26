---
layout: post
title: Docker volume
categories: Docker
excerpt_separator:  <!--more-->
comment:false
---

- docker volume 이란?

도커는 이미지로 컨테이너를 생성하면 이미지는 읽기전용이라 쓰기가 불가능 합니다.

그렇기 때문에 컨테이너 계층에 변화되는 데이터들이 저장이 되는데,
이럴경우에 컨테이너가 삭제되면 그동안 저장된 데이터들이 삭제 됩니다.
그렇게되면 복구도 불가능해지기 때문에
컨테이너의 데이터들을 영속적으로 데이터 활용 할 수 있는 방법이 
docker volume 을 이용하면 가능합니다.


docker run -d
—name {컨테이너 이름}
-e ~~
— link 
-p 80
images

ex) -d 컨테이너를 백그라운드에서 동작하는 어플리케이션으로 실행하도록 합니다.
-e : 환경변수 설정, 내가 volume 찾아본 이유는 postgre sql 을 이용하려고 찾아보았는데 
이런경우에는 postgresql에서 이용할 비밀번호를 저장 할때 이부분을 이용한다.

-e test_DB_PASSWORD=1234
—link test:postgresql	=> test라는 컨테이너를 postgres 라는 이름으로 접근하겠다는 뜻

내가 사용한 데이터들을 볼륨으로 만들어낼 수 있고
어떤 컨테이너에  가져다 붙혀서 사용할 수 있다.

** volume 만드는 방법
docker  create volume —name test

![image](https://user-images.githubusercontent.com/40929370/74097973-dbca6800-4b55-11ea-94aa-85a5a62715aa.png)

![image](https://user-images.githubusercontent.com/40929370/74097980-fbfa2700-4b55-11ea-9677-7f6cd5030032.png)

![image](https://user-images.githubusercontent.com/40929370/74097985-06b4bc00-4b56-11ea-9e35-0a7adb2fdf6f.png)

