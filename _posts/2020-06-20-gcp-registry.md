---
layout: post
title: GCP 레지스트리 등록
comments: false
categories: GCP
---

GCP 에서 docker image 를 pull&push 관리 하기 위해서 기록
    - gcp에 docker 이미지 올려놓는 이 ?? 
    
![image](https://user-images.githubusercontent.com/40929370/85203347-1932d380-b348-11ea-8d5a-efc0db2e52f5.png)

<b>google Container Registry API</b> 사용 설정을 눌러 준다.


2. **Google Cloud SDK 설치**

   GCP로부터 이미지를 pull &  push 할 때, gcloud 명령어가 필요하기 때문에 Cloud SDK 설치해야 한다. 

​	gcloud init 쳐서 해주고 그 후,  gcloud auth login 해줘서 인증 해줘야 한다.



그리고 나서 google Cloud Platform > 목록 > IAM 및  관리자 > 서비스  계정

내가 만들어야 할 서비스 프로젝트 계정 만들어주고 계정 권한은 프로젝트 > 소유자로 만들어준다.

![image](https://user-images.githubusercontent.com/40929370/85216408-3d7dc700-b3bf-11ea-95a9-58ec4810c5c0.png)


그 후, 다음으로 넘어가서 키 만들고 json 형식으로 만들고 완료 눌러서 마무리 합니다.

(만들어진 키는 보관해야합니다.)


키를 만들면서 다운로드된 json 파일을 리눅스의 /usr/lib64/google-cloud-sdk/bin 으로 옮깁니다.

이제 아래 명령어를 통해 서비스 계정 인증을 위해 아래 명령어를 입력해줍니다. (만약 gcloud 커맨드를 찾지 못한다면 /usr/lib64/google-cloud-sdk/bin 으로 이동하셔서 ./gcloud 로 사용 해주세요) 

```dockerfile
gcloud auth activate-service-account --key-file=/usr/lib64/google-cloud-sdk/bin/[옮긴 json파일명]
gcloud auth configure-docker

# gcloud 커맨드를 인식하지 못할 경우
cd /usr/lib64/google-cloud-sdk/bin/
./gcloud auth activate-service-account --key-file=/usr/lib64/google-cloud-sdk/bin/[옮긴 json파일명]
./gcloud auth configure-docker
```



json 파일도 인증이 완료 되었다면 리눅스에서 삭제 해줍니다. (gcloud CLI가 키를 저장하여 리눅스에서 삭제해도 계속 유지된다고 합니다)

### 도커 이미지 업로드 / 다운로드

* 도커 이미지를 업로드 하기 전 이미지의 태그를 설정해줘야 합니다.    
```dockerfile
# docker tag [로컬 이미지명][Google Container Registry 호스트명]/[프로젝트ID]/[이미지명]
ex) docker tag jellystore asia.gcr.io/jellystore/jellyStore
```


Google Container Registry  호스트 명은 이미지  저장위치에 따라 아래중 하나를 사용하면된다.

 * us.gcr.io  - 미국
 * eu.gcr.io - 유럽
 * asia.gcr.io - 아시아

```text
docker build -t hostname/project_id/dockerImage

docker push hostname/project_id/dockerImage

```

![image](https://user-images.githubusercontent.com/40929370/86599971-58188880-bfda-11ea-9420-d08206997910.png)
올라가져 있는걸 확인 할 수 있다.. !



