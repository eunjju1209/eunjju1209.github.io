---
layout: post
title: 정렬
comments: false
categories: Algorithm
---
바이너리 탐색 > 이진트리를 보고 유무 탐색

** 탐색 ( 순차 & 해쉬 & 이진)<br/>
** 이진탐색에 따라 공부함,

<b> - 이진탐색 (Binary Search)</b>

Q. 다음 1~30 병뚜껑에는 각각 1 ~ 100 사이의 번호가 표시되어 있다.<br/>
이중에 70이 있을지 없을지 확인하는 방법을 찾아보세요.

조건 : <br/>
1) 가장 적게 병을 따야 한다.<br/>
2) 각 병뚜껑에 씌여진 번호는 낮은 번호 순으로 기입되어 있다.

 ** 정렬이 되어있다는 전제 조건,, 

순차 -> 하나씩 앞에서 부터 따는 .. 

하지만 정렬이 되어있다는 전제 조건이라면 가운데의 병을 찾아서 값 비교 한다.

15의 병을 땄을 때는 60 이라고 생각하면 16 ~ 30 에 70이 있을 확률이 높음

22 의 병을 뚜껑을 땄을때는 75이다. 그럼 왼쪽의 병을 기준으로 찾는다.<br/>
라고 하면서 조금씩 줄여가본다 —> 이러한 방식은 이진탐색 방법

```
** 그러므로 이진탐색이 순차탐색보다 빠르다.
```

이진 탐색코드 

분할 정복 알고리즘과 이진 탐색
```
- 분할 정복 알고리즘 (Divide and Conquer) —> 재귀용법 에서 많이 쓰인다,
	- Divide : 문제를 하나 또는 둘 이상으로 나눈다.
	- Conquer: 나눠진 문제가 충분히 작고, 해결이  가능하다면 해결하고, 그렇지 않다면 다시 나눈다.
- 이진 탐색 
	- Divide: 리스트를 두 개의 서브 리스트로 나눈다.
	- Conquer 
		- 검색할 숫자 (search) > 중간값 이면, 뒷 부분의 서브 리스트에서 검색할 숫자를 찾는다.
		- 검색할 숫자 (search) < 중간값 이면, 앞 부분의 서브 리스트에서 검색할 숫자를 찾는다.
```

<b>- 순차 탐색 (Sequential Search)</b>

 * 순차 탐색 (Sequential Search)
	- 탐색은 여러 데이터 중에서  원하는 데이터를 찾아내는 것을의미
	- 데이터가 담겨있는 리스트를 앞에서부터 하나씩 비교해서  원하는 데이터를 찾는 방법

 * 최악의 경우리스트  길이가 n일때, n번비교해야함 
	- O(n)