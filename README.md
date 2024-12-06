# TMFCRTTL
A TrackMania (Nations/United) Forever server (XASECO) plugin which calculates rounds per challenge (for Cup) or points limit (for Rounds/Team), according to an admin-defined custom time limit, based on the duration (author time) of the track, as well as the finish timeout (ie. the countdown that starts once the first player finishes the track, after which all other unfinished players retire).

> [!NOTE]
> This repository is currently an **early work-in-progress**, any and all information is subject to change without notice and may be missing or incorrect.

## Calculations
The formula for "rounds per challenge" (RPC) is as follows:
```
RPC = TL / (AT + FT + IT)
```
...where:
- TL is the custom-set timelimit (using the admin-only `/crt_timelimit` command)
- AT is the track's author time
- FT is the track's finish timeout
- IT is the "intermission time", designated to account for the synchronization delay inbetween rounds (ie. "Please wait..." time) - currently hardcoded to 10 seconds

Cup mode directly takes its "rounds per challenge" count from this value. However, additional calculations are made for Rounds and Team mode, in order to determine the ideal "points limit" (PL).

The formula for PL in Rounds mode is as follows:
```
PL = AVG * RPC
```
...where AVG is the average of the sum of the rounds points of 1st and 2nd place (by default, 10 and 6 respectively, thus in this case `AVG = (10 + 6) / 2 = 8`).

The formula for PL in Team mode is as follows:
```
PL = RPC / 2
```
...where the resulting value is always rounded up (for example, if RPC is 5, PL will be 3).