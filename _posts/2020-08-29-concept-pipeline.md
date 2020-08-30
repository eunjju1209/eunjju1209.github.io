---
layout: post
title: pipeline (CI/CD)
comments: true
categories: concept
---

여러 문서를 보다보면 `파이프라인` 이라는 단어를 많이 볼 수 있을것이다.<br/>
정확하게는 모르고 대충 큰뜻만 알고있다가 gcp ci/cd 포스팅을 하게되면서 의미를 정확하게 짚고 넘어가려고 <br/>
파이프라인에 대해 포스팅을 해보려고 한다. <br/>

일단 내가 위키백과에서 찾은 파이프라인에 대한 정보이다. <br/>
<a cursor="pointer" target="_blank" href='https://ko.wikipedia.org/wiki/%ED%8C%8C%EC%9D%B4%ED%94%84%EB%9D%BC%EC%9D%B8_(%EC%BB%B4%ED%93%A8%ED%8C%85)'>
    https://ko.wikipedia.org/wiki/%ED%8C%8C%EC%9D%B4%ED%94%84%EB%9D%BC%EC%9D%B8_(%EC%BB%B4%ED%93%A8%ED%8C%85)
</a> 
한 데이터 처리 단계의 출력이 다음 단계의 입력으로 이어지는 형태로 연결된 구조를 말합니다. (wiki) 

근데 내가 원하는 CI/CD의 파이프라인이 위키에 내용과 좀 다른것 같아서 여러 사이트 돌아다니면서 다시 검색을 했다.<br/>
그러다가 내가 궁금했던 부분을 잘 정리 해놓은 사이트가 나와서 공유하려고 한다.

지금 포스팅 하는 부분은 내가 궁금한 부분만 적어놓았고,<br/> 밑에 참조 블로그로들어가면 `파이프라인 & CI/CD & CI/CD 배포 방식` 에대해 적혀져 있으니 자세한것은 참조 블로그에서 확인하는게 좋을것 같다. 

* CI/CD의 파이프라인(pipeline)은 개발자나 DevOps 전문가가 효율적이면서도 확실하게 그들의 코드를 <br/>
컴파일(Compile), 빌드(Build) 그리고 그들의 프로덕션 컴퓨팅 플랫폼에 배포(Deploy) 하게<br/>
해주는 자동화된 프로세스들의 묶음(set)이다.

```html
* 파이프라인 에서 가장 일반적인 컴포넌트들이 있다.
    - 빌드 자동화(buildautomation) / 지속적 통합(continuous integration)
    - 테스트 자동화(test automation)
    - 배포 자동화(deployment automation)
```

- 참조
<a href='https://linux.systemv.pe.kr/%EC%86%8C%ED%94%84%ED%8A%B8%EC%9B%A8%EC%96%B4-%EC%97%94%EC%A7%80%EB%8B%88%EC%96%B4%EB%A7%81%EC%97%90%EC%84%9C-%ED%8C%8C%EC%9D%B4%ED%94%84%EB%9D%BC%EC%9D%B8pipeline%EC%9D%80-%EB%AC%B4%EC%97%87'>
https://linux.systemv.pe.kr/%EC%86%8C%ED%94%84%ED%8A%B8%EC%9B%A8%EC%96%B4-%EC%97%94%EC%A7%80%EB%8B%88%EC%96%B4%EB%A7%81%EC%97%90%EC%84%9C-%ED%8C%8C%EC%9D%B4%ED%94%84%EB%9D%BC%EC%9D%B8pipeline%EC%9D%80-%EB%AC%B4%EC%97%87/
</a>

    
 
