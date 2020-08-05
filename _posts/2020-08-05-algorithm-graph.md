---
layout: post
title: 그래프 (DFS&BFS)
comments: false
categories: Algorithm
---
너비 우선 탐색 (Breadth-First Search)

 * 탐색 알고리즘
	-> 그래프에서 탐색한다는뜻
		= 특정 노드를 탐색하겠다는 뜻

	* 노드 탐색
		- 너비 우선 탐색	
		- 깊이 우선 탐색


너비 우선 탐색 (BFS) 
	- 정점들과 같은 레벨에 있는 노드들 먼저 탐색하는 방식
깊이 우선 탐색 (DFS)
	- 정점의 자식들을 먼저 탐색하는 방식


 * 사이클이 없는 방식에서 탐색하는 방식이다.

 ![image](https://user-images.githubusercontent.com/40929370/89375657-112cc880-d729-11ea-9774-994ae004f002.png)

빨간 화살표는 탐색을 하기 위해서 노드 이동하는 부분을 그림으로 그려놓음


* BFS 방식 : A - B - C - D - G - H- I - E - F - J
* DFS 방식: A - B  - D - E  - F - C - G -H - I - J


** BFS 알고리즘 코드로 작성


 * 자료 구조 큐를 활용함<br/>
	need_visit & visited 큐<br/>

* 깊이 우선 탐색 (Depth-First Search)<br/>

자기와 연결된 노드의 밑에 맨 밑에까지 탐색하고<br/>
그게 리프 노드이면, 그 상위의 노드로 가서 다른 리프노드를 먼저 끝까지 탐색 하는것<br/>


* DFS 알고리즘 구현<br/>
	need_visit 스택, visited 큐 활용한다<br/>

시간복잡도<br/>
	* 일반적인 DFS 시간 복잡도<br/>
		노드 수 : V <br/>
		간선 수 : E <br/>
		- 위 코드에서 while need_visit V+E 번만큼 수행함

	* 시간 복잡도: O(V+E)
