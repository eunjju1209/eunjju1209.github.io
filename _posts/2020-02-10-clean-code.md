---
layout: post
title: 의미있는 맥락을 추가하라
categories: CleanCode
---

이 소제목의 내용을 보고 반성을 할 수 있게 만든 내용이였다. <br/>

하나의 `function` 안에서 여러가지 액션이 일어나야 하는 경우가 있는데,
하나의 함수 안에서 여러개의 역할을 하는걸 한번에 다 넣는 경우가 있는데, 
이렇게 하면 가독성이 떨어진다는걸 알지만 시간이 없다는 핑계와 나중에 리팩토링을 할거라고 생각하고 몇개의 함수를 만든적 있다.

책에서 권장하지 않는 예제 소스가 있었는데 매우, 내가 회사에서 짜놓은 소스와 비슷해서 반성의 의미로 적어놓으려고 한다.


    private void printGuessStatistics(char candidate, int count) {
        String number;
        String verb;
        String  pluralModifier;
        
        if (count == 0) {
            number  = "no";
            verb    = "are";
            pluralModifier = "s";
        } else if (count  == 1)  {
            number = "1";
            verb = "is";
            pluralModifier  = "";
        } else  {
            number = Interger.toString(count);
            verb =  "are";
            pluralModifier = "s";
        }
        
        String guessMessage = String.format(
            "There %s %s %s%s", verb, number, candidate, pluralModifier 
        );
        
        print(guessMessage);
    }


위에 있는 소스가 내가 회사에서 적어놓을 만한 소스이다.. <br/>
내가 반성하고자 하는건 if, elseif scope 안에서 처리하는 부분을 function 으로 안 만들고 
 재사용성 없이 만든 부분을 반성하고자 쓰는것이다.

책에서 권장한, 맥락이 분명한 변수

    public class GuessStatisticsMessage {
        private String number;
        private String verb;
        private String pluralModifier;
        
        public String make(char candidate, int count)  {
            createPluralDependentMessageParts(count);
            return String.format(
                "There %s  %s %s%s", verb, number, candidate,  pluralModifier
            );
        }
    
        
        private void  createPluralDependentMessageParts(int count) {
            if (count == 0) {
                thereAreNoLetters();
            } else if (count == 1) {
                thereIsOneLetter();
            } else {
                thereAreManyLetters(count);
            }
        }
        
        private void thereAreManyLetters(int count) {
            number = Integer.toString(count);
            verb = "are";
            pluralModifier = "s";
        }
        
        private void thereIsOneLetter() {
            number = "1";
            verb = "is";
            pluralModifier = "";
        }
        
        private void thereArenoLetters() {
            number = "no";
            verb = "are";
            pluralModifier = "s";
        }
    }

사실 모든 소스는 재 사용성을 고려해서 모든걸 만들어야 되는걸 알지만
실제로 잘 지켜지지 않고 실천하지 못하는 부분을 반성해야 할 것같다.

