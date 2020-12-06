---
layout: post
title: dockerfile 생성
comments: true
categories: Docker
---

php 7.3 버전 대에 있는 컨테이너가 필요해서, 내가 필요한 패키지들을 갖고 와서 컨테이너를 만들려고 한다.

```
FROM alpine:3.12
```

도커 파일들 중에 `alpine` 이라고 적혀져 있는 것들을 자주 볼 수 있다.

자주 보는데 정확히 무슨 역할을 하는 건지 잘 몰라서 찾아보았다.<br/>
그러고 나서 내가 필요한 패키지들을 선택해서 이미지를 만들고 난뒤에 컨테이너를 생성하고 나서 오류가 나서<br/>
어떻게 수정했는지 공유하기 위함 포스팅이다.

![image](https://user-images.githubusercontent.com/40929370/99763418-285bbe80-2b3e-11eb-9778-32fea40b29b0.png)

오류 내용 
`[20-Nov-2020 05:38:24] ERROR: FPM initialization failed`
`[20-Nov-2020 05:38:24] ERROR: failed to post process the configuration`
`[20-Nov-2020 05:38:24] ALERT: [pool www] user has not been defined`

찾아보았는데 간략해서 설명하자면 
`alpine` 이란  
<b>cloud 환경을 고려한 가벼운 linux 이미지 </b> 라고 한다.


** 참고

git hub Tim de Pater<br/> 
https://github.com/TrafeX/docker-php-nginx