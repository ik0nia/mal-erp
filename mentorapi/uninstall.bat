@echo off
echo Stopping MentorAPI service...
sc stop MentorAPI
echo Removing MentorAPI service...
sc delete MentorAPI
echo Done.
pause
