@echo off
rem 全量重抓：五维 / 信物 / 技能 / 糖果 / 简介 / 立绘 全部按官方最新重来（约 15-20 分钟，要联网）
rem   export_all.bat          清空重灌（会覆盖后台手改的字段）
rem   export_all.bat keep     保留已有条目（只补新增的艾比，不改旧的）
cd /d "%~dp0.."

set KEEPFLAG=
if /i "%~1"=="keep" set KEEPFLAG=--keep

echo == 1/2 抓数据（官方接口 + 本地包 + 图片）==
python build\export.py --refresh || goto :fail
echo.
echo == 2/2 导入数据库 %KEEPFLAG% ==
php build\import.php %KEEPFLAG% || goto :fail
echo.
echo 完成。
pause
exit /b 0

:fail
echo.
echo 出错了，看上面的提示。data\aibi.json 和 data\aibi.db 都还在，可以重跑。
pause
exit /b 1
