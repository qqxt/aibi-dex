@echo off
rem 只刷新票数与评级：几秒完成，不动五维/信物/技能/简介，也不动后台手改的内容
cd /d "%~dp0.."
echo == 1/2 抓官方榜单，更新 data\aibi.json ==
python build\export.py --votes-only || goto :fail
echo == 2/2 写回数据库 ==
php build\import.php --votes-only || goto :fail
echo.
echo 完成。刷新时间记进 meta.votes_updated_at，页面底部和详情页「数据快照」会显示。
pause
exit /b 0

:fail
echo.
echo 出错了，看上面的提示。
pause
exit /b 1
