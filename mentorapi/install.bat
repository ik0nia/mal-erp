@echo off
echo ========================================
echo  MentorAPI - Install as Windows Service
echo ========================================
echo.

:: Install service
sc create MentorAPI binPath= "%~dp0mentorapi.exe" start= auto DisplayName= "MentorAPI - WinMentor Bridge"
sc description MentorAPI "REST API bridge for WinMentor DocImpServer COM interface"

:: Auto-restart on failure: wait 10s, then restart (max 3 times)
sc failure MentorAPI reset= 86400 actions= restart/10000/restart/10000/restart/10000

:: Start the service
sc start MentorAPI

echo.
echo Done! Service installed with auto-restart on failure.
echo Check: http://localhost:9500/api/health
pause
