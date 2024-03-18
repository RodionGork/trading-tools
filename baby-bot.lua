function runbot(fname, cfg)
  local win = cfg.window
  local swin = cfg.swindow
  local fall = 1 - cfg.fall/100
  local rise = 1 + cfg.rise/100
  local tp = 1 + cfg.takeprof/100
  local sl = 1 - cfg.stoploss/100

  local sum = 1000
  local order = nil
  local cnt = 0
  local wait = 0
  local firstline = true
  local avg, avg2, op, hi, lo, cl

  for line in io.lines(fname) do
    local vals = {}
    for w in line:gmatch('%d+%.%d+') do
      table.insert(vals, tonumber(w))
    end
    op, hi, lo, cl = table.unpack(vals)
    mid = (hi+lo)/2
    -- from here bot logic follows
    if firstline then
      firstline = false
      avg = lo
      avg2 = lo
    end
    avg = (avg * (win-1) + lo) / win
    avg2 = (avg2 * (swin-1) + lo) / swin
    if order == nil then
      if wait > 0 then
        wait = wait - 1
      else
        if cl < avg * fall and cl > avg2 * rise then
          order = cl
          cnt = cnt + 1
        end
      end
    else
      if lo <= order * sl then
        sum = sum * sl * 0.998
        order = nil
        wait = win * 3
      elseif hi >= order * tp then
        sum = sum * tp * 0.998
        order = nil
      end
    end
  end

  if order ~= nil then
    sum = sum * cl / order * 0.998
  end
  return sum, cnt
end

config = {window=360, swindow=6, fall=0.8, rise=0.2, takeprof=1.5, stoploss=5.0}
for k, v in pairs(config) do io.write(k .. '=' .. v, ' ') end
print()
dirname = 'bnc-data/'

dir = io.popen('ls -w1 ' .. dirname)
money = 1000
tot = 0
for name in dir:lines() do
  sum, cnt = runbot(dirname .. name, config)
  tot = tot + sum
  money = money * sum / 1000
  -- print(sum, cnt)
end
print(money, tot / 26)
