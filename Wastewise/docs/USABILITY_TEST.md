# Usability test protocol

A small, informal test to measure **task completion**, **usability score** and **alert relevance**,
the measures named in the project contract. With about 5 participants it shows problems and gives an
indication. It cannot support statistical claims.

## Ethics

- Complete the university ethics screening before running sessions with other people.
- Use a prepared demo account with made-up food data. Never ask participants for real personal data.
- Tell participants: it is voluntary, they can stop at any time, you are testing the system and not
  them, and results are anonymous (record "P1, P2" only).
- Do not record faces or voices. Keep notes by hand.

## Setup

Create a demo household with about 10 items across categories, some expiring in 1 to 5 days, a few with
history so the score has learned something. Keep the same demo data for every participant.

## Tasks (read each aloud; do not help unless stuck for 2 minutes)

| Task | Instruction | Success means |
|---|---|---|
| T1 | Log in with the demo account | Reaches the dashboard |
| T2 | Add a carton of milk bought today that expires in 6 days | Item appears in the list |
| T3 | Find which item the system says you should use first | Names the top-ranked item |
| T4 | Record that the yoghurt was eaten 2 days ago | Recorded with the right date |
| T5 | An old item expired last week and you are not sure what happened to it. Record that | Chooses "I am not sure" or another honest option |
| T6 | Import the items from the sample receipt text | Rows reviewed and saved |

**Task completion** = tasks completed without help / (tasks attempted). Record per task: completed
(yes / with help / no), time taken, and any confusion you saw.

## Alert relevance

Show each participant the demo list twice: ranked by adaptive risk and ranked by soonest expiry.
For the top 3 items in each list, ask: *"Would you want to use this item first? Yes / No."*
Alert relevance = "Yes" answers / items shown, calculated separately for each ranking. Show the two
lists in a different order for different participants, so order does not bias the answer.

## System Usability Scale (SUS)

After the tasks, each participant rates 10 statements from 1 (strongly disagree) to 5 (strongly agree):

1. I think I would like to use this system frequently.
2. I found the system unnecessarily complex.
3. I thought the system was easy to use.
4. I think I would need the support of a technical person to use this system.
5. I found the various functions in the system were well integrated.
6. I thought there was too much inconsistency in this system.
7. I would imagine most people would learn to use this system very quickly.
8. I found the system very cumbersome to use.
9. I felt very confident using the system.
10. I needed to learn a lot of things before I could get going with this system.

**Scoring:** for odd-numbered statements subtract 1 from the answer; for even-numbered statements
subtract the answer from 5. Add the 10 results and multiply by 2.5. The score is 0 to 100. Report the
mean and the range, and be clear that 5 people is a small sample.

## Recording sheet

| Participant | T1 | T2 | T3 | T4 | T5 | T6 | Completion | SUS | Relevance (adaptive) | Relevance (expiry) | Notes |
|---|---|---|---|---|---|---|---|---|---|---|---|
| P1 | | | | | | | | | | | |
| P2 | | | | | | | | | | | |
| P3 | | | | | | | | | | | |
| P4 | | | | | | | | | | | |
| P5 | | | | | | | | | | | |
