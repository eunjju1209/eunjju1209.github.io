---
layout: post
title: 프로그래머스 - 여행계획(javascript)
comments: true
categories: Algorithm
---

PHP 개발을 주로 하고 있고 전 회사에서 잠깐 typescript로 개발 경험이 있어서 알고리즘을 풀 때 
자바스크립트로 선택해서 풀고있습니다.

여기에 올리는 이유는 알고리즘을 잘 못 풀어서 통과를 못했기때문에 누군가가 테스트 케이스를 댓글로 알려줬으면 해서 올립니다.<br/>

제공하는 실행 테스트로는 다 pass 되는데 어디부분에서 문제가 있는지 잘 모르겠습니다. <br/>

```javascript

function bfs(dest, tickets, answer) {
    tickets.forEach(function(item, index) {
        if (tickets.length == 0) {
            return answer;
        }
        
        if (dest == item[0]) {
            dest = item[1];
            answer.push(item[1]);
            tickets.splice(index, 1);
            return bfs(dest, tickets, answer);
        }
    });
}

function solution(tickets) {
    var answer = [];
    
    const start = "ICN";
    let temp = {};
    let temp2 = [];
        
    answer.push(start);
    
    // 목적지 문자열 기준으로 정렬해주기
    tickets.forEach((item, index) => {
        if (item[0] == start) {
            temp[index] = item[1];
            temp2.push(item[1]);
        }
    });

    let dest = temp2.sort()[0];
    const destIndex = Object.keys(temp).find(key => temp[key] === dest);
    
    answer.push(dest);
    tickets.splice(destIndex, 1);
    
    tickets = tickets.sort();
    bfs(dest, tickets, answer);
    
    return answer;
}
```
